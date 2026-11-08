<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;

/**
 * T-29: repair stored subjects, From and To headers and extracted bodies of messages labelled as a
 * Latin character set but sent as (partly) UTF-8. Headers are repaired with the raw header from the
 * archived message; the body text is cleared and extracted again by the rule runner. Then all
 * messages are re-evaluated. Used by data migrations.
 */
final class CharsetRepair
{
    private const MOJIBAKE = '/\xC3[\x82\x83\xA2]\xC2[\x80-\xBF]/';

    /** @return int number of messages changed */
    public static function run(BaseConnection $db, string $archivePath): int
    {
        $archive = rtrim($archivePath, '/');
        $lastId  = 0;
        $changed = 0;
        do {
            $rows = $db->table('messages')->select('id, subject, header_from, header_to, body_text, eml_path')
                ->where('id >', $lastId)->orderBy('id')->limit(200)->get()->getResultArray();
            foreach ($rows as $r) {
                $lastId = (int) $r['id'];
                $update = [];
                $file   = $archive . '/' . $r['eml_path'];
                $eml    = null;
                foreach (['subject' => 'Subject', 'header_from' => 'From', 'header_to' => 'To'] as $column => $header) {
                    if ($r[$column] !== null && preg_match(self::MOJIBAKE, $r[$column])) {
                        $eml ??= ! str_contains($r['eml_path'], '..') && is_file($file) ? (string) file_get_contents($file) : '';
                        $fixed = Charset::repairHeader($r[$column], Charset::rawHeader($eml, $header));
                        if ($fixed !== $r[$column]) {
                            $update[$column] = $fixed;
                        }
                    }
                }
                if ($r['body_text'] !== null && preg_match(self::MOJIBAKE, $r['body_text'])) {
                    $update['body_text'] = null; // extracted again by the rule runner
                }
                if ($update !== []) {
                    $db->table('messages')->where('id', $r['id'])->update($update);
                    $changed++;
                }
            }
        } while ($rows !== []);

        if ($changed > 0) {
            $db->query("UPDATE settings SET value = CAST(value AS UNSIGNED) + 1 WHERE name = 'rules_version'");
        }

        return $changed;
    }
}
