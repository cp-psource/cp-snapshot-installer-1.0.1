ENGLISH:

If all else fails, this script restores your CP snapshot

To actually restore an archive, we need 2 things:

1) `snapshot-installer.php`
2) Actual backup archive :)

Once you have both, copy them over to a destination directory of your choice
(within your webroot, of course). The installer will expect the backup archive
in the same directory, and named after Snapshot v3 full backup archive
file convention (i.e. `[0-9a-f]{12}\.zip` or `full_*.zip`).

Then call up snapshot-installer.php via your browser (e.g.: https://meinehompage/snapshot-installer.php)

DEUTSCH

Wenn nichts mehr geht, stellt dieses Skript Deinen CP-Snapshot wieder her

Um ein Snapshot Archiv wiederherzustellen, benötigen wir zwei Dinge:

1) `snapshot-installer.php`
2) Aktuelles Backup-Archiv :)

Sobald Du beide hast, kopiere sie in ein Zielverzeichnis Deiner Wahl
(natürlich in Deinem Webroot). Der Installer erwartet das Sicherungsarchiv
im selben Verzeichnis und benannt nach dem vollständigen Backup-Archiv von Snapshot v3
Dateikonvention (z. B. `[0-9a-f]{12}\.zip` oder `full_*.zip`).

Rufe anschliessend snapshot-installer.php über Deinen Browser auf (z.B: https://meinehompage/snapshot-installer.php)