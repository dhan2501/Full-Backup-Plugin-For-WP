=== WP Complete Backup ===
Version: 2.1.0
Requires PHP: 7.4+
Requires WordPress: 5.0+

A self-contained WordPress plugin to back up your entire site (database + files),
restore/import a backup (including from a different site), export individual
pages/posts with images and metadata, and safely update the domain/URL after
moving a site.

== INSTALLATION ==

1. Go to your WordPress admin dashboard -> Plugins -> Add New -> Upload Plugin.
2. Choose the "wp-complete-backup.zip" file and click "Install Now".
3. Click "Activate".
4. A new menu item "Complete Backup" will appear in the left sidebar (database icon).

Alternatively, upload the unzipped "wp-complete-backup" folder to
/wp-content/plugins/ via FTP/SFTP, then activate it from the Plugins screen.

To IMPORT a backup made on a different WordPress site, install and activate
this same plugin there too, then use the "Restore / Import" tab.

== REQUIREMENTS ==

- PHP ZipArchive extension enabled (used for all ZIP creation/extraction).
  Almost all hosts have this enabled by default.
- Sufficient disk space in wp-content/ for the backup files (a full backup
  duplicates your media library size, roughly).
- For very large sites, increase PHP's max_execution_time / memory_limit /
  upload_max_filesize / post_max_size if your host allows it. The plugin
  tries to raise these automatically, but upload_max_filesize and
  post_max_size in particular are read by PHP BEFORE your request reaches
  WordPress, so a runtime override often has no effect — if large-file
  uploads fail, ask your host to raise these in php.ini, or split the backup
  into Database-only + Media-only pieces instead of one giant full backup.

== FEATURES ==

1. FULL SITE BACKUP
   One click backup that creates a single ZIP containing:
   - database.sql (full database dump: all tables, structure + data)
   - wp-content/ (themes, plugins, uploads/media — everything except the
     plugin's own backup storage folder)
   - backup-info.json (site URL, WP/PHP version, table prefix, timestamp)

2. DATABASE-ONLY BACKUP
   Exports just the database to a .sql file, zipped for a smaller download.

3. MEDIA-ONLY BACKUP
   Exports just the wp-content/uploads folder (all media library files),
   zipped.

4. PER-PAGE / PER-POST EXPORT
   Search and select any number of pages or posts and export them. Each
   export is a ZIP that includes:
   - content.html      -> the rendered post/page content as standalone HTML
   - meta.json          -> title, slug, dates, author, permalink, taxonomies
                            (categories/tags), and ALL custom fields / meta
                            (this covers most SEO plugin fields too, since
                            they're stored as post meta)
   - images/             -> the featured image, any image attached to the
                            post in the Media Library, and any inline images
                            referenced in the content that live in your
                            uploads folder

   You can export a single item or select many and export them together —
   bulk exports place each item in its own subfolder plus an index.json
   summarizing what's inside.

5. RESTORE / IMPORT A BACKUP (own site OR a different site)
   The "Restore / Import" tab lets you:
   - Upload a backup .zip or .sql (made by this plugin, on this site or any
     other WordPress site running this plugin)
   - Inspect it first — it reports whether it contains a database dump,
     wp-content files, and/or an uploads folder, plus the original site URL
     if available, BEFORE you commit to anything
   - Choose exactly what to restore: database only, files only, or both
   - Optionally take an automatic safety backup of the CURRENT site before
     the restore runs, so a mistake is recoverable
   - Database import handles standard SQL dumps line-by-line, correctly
     parsing multi-line INSERT statements and values containing semicolons,
     quotes, or newlines — and will remap the table prefix automatically if
     the dump's prefix differs from this site's current prefix (e.g.
     restoring a "wp_" dump onto a site using "wp7x_")
   - File restore extracts wp-content (or just uploads, for media-only
     backups) into the live site, skipping anything that would land inside
     the plugin's own backup-storage folder for safety, and rejecting any
     zip entry containing ".." path segments (path-traversal protection)

6. MIGRATE DOMAIN (safe URL search & replace)
   After restoring a backup from a different domain (old site, staging URL,
   localhost, etc.), use the "Migrate Domain" tab to update every reference
   to the old URL throughout the database — INCLUDING inside PHP-serialized
   data (widget settings, theme options, and similar), without corrupting it,
   AND including internal links and image URLs inside your page/post content
   (e.g. <a href="http://old.com/...">, <img src="http://old.com/...">),
   since those are stored as ordinary text in the posts table and are
   scanned the same way as everything else.

   This matters because a naive SQL find/replace breaks serialized strings:
   PHP's serialization format stores an exact byte-length prefix for every
   string (e.g. s:18:"http://old.com/wp";), and if you change the string's
   length without updating that prefix, PHP's unserialize() fails and the
   data is silently lost. This plugin detects serialized values, safely
   unserializes them, replaces inside the actual data, and re-serializes
   with correct lengths — so widgets and similar data keep working. This
   behavior was verified against a real MySQL/MariaDB database, including a
   replacement that changes the string's length (e.g. a short domain
   replaced with a much longer one), confirming the data still unserializes
   correctly afterward.

   One column is deliberately left untouched: wp_posts.guid. WordPress
   treats the GUID as a permanent identifier that's not supposed to change
   after a post is published — leaving it alone matches the same convention
   used by WP-CLI's own `wp search-replace` command.

   The tool also automatically checks the opposite protocol of whatever you
   type (http <-> https) and the protocol-relative form ("//domain.com"),
   since a very common reason internal links don't get updated is that the
   old site mixed http and https links and the person only typed one of
   them into the Old URL field. The result report shows exactly which
   variant(s) were actually found in your data.

   Always run "Preview Changes (Dry Run)" first — it reports exactly how
   many occurrences would change in each table without writing anything, so
   you can confirm the old URL is right before committing. The site URL and
   home URL options are also explicitly updated as part of this process.

7. DOMAIN-MISMATCH DETECTION ON UPLOAD
   When you upload or select a backup to restore, the Restore tab inspects
   it and tries to detect the ORIGINAL site's URL — either from the
   backup-info.json manifest (full backups) or by scanning the database dump
   directly for the "siteurl" option (works for .sql files and zips without
   a manifest too). It then compares that to your current site's URL and
   shows a clear banner:
     - "This backup is from a different site" (red) if the domains differ,
       with a button that jumps straight to the Migrate Domain tab and
       pre-fills the Old/New URL fields for you
     - "This backup matches the current site's domain" (green) if they're
       the same, so you know no migration step is needed
     - "Could not detect the original domain" (blue) if no siteurl could be
       found at all, so you can still migrate manually if needed
   The same reminder appears again right after a database restore
   completes, in case it was missed during inspection.

8. BACKUP MANAGEMENT
   All generated/uploaded backups are listed on the plugin's dashboard page
   with file size and creation date. You can download, restore, or delete
   them directly.

== WHERE ARE BACKUPS STORED ==

All backup ZIP/SQL files (including ones you upload for restoring) are
stored in:
   wp-content/wpcb-backups/

This folder is automatically protected with a .htaccess rule (Apache) and a
web.config rule (IIS) to deny direct browser access — backups should only be
downloaded through the plugin's admin page (which uses your logged-in admin
session), not by guessing the file URL.

IMPORTANT: Always download a copy of your backups to your own computer or
cloud storage (Google Drive, Dropbox, etc.) after creating them. Don't rely
solely on files stored on the same server — if the server fails, on-server
backups are lost too.

== MOVING A SITE FROM ONE DOMAIN TO ANOTHER: RECOMMENDED STEPS ==

1. On the OLD site: Backup tab -> "Create Full Backup". Download the ZIP.
2. On the NEW site/server: install + activate this plugin.
3. Restore / Import tab -> upload the ZIP -> let it inspect -> check both
   "Restore database" and "Restore files" -> tick the safety-backup option
   if the new site already has content you care about -> Restore Now.
4. Migrate Domain tab -> enter the OLD domain in "Old domain / URL" (the
   inspector usually pre-fills this from the backup's manifest) and the
   NEW site's current domain in "New domain / URL" -> Preview Changes ->
   review the table-by-table counts -> Run Replacement.
5. Log out and back in (sessions/cookies are tied to the domain), then check
   the site, especially any widgets, menus, and theme customizer settings.

== RESTORING WITHOUT THIS PLUGIN (manual fallback) ==

If you ever need to restore without using the Restore tab (e.g. moving to a
host where you can't install plugins yet):

- Database (database.sql): Import via phpMyAdmin, Adminer, or the WP-CLI
  command: wp db import database.sql
- Files (wp-content folder): Unzip and upload the themes/plugins/uploads
  folders to your server via FTP/SFTP, overwriting or merging as needed.
- Individual page/post exports (content.html + meta.json + images): These
  are meant as a portable/readable backup of specific content — you can
  manually recreate the page/post in WordPress using the HTML content,
  re-upload the images, and reapply key metadata, or use them as a reference
  / migration aid.
- For the domain/URL update without this plugin, WP-CLI's
  "wp search-replace" command does the same serialization-safe replacement;
  see https://developer.wordpress.org/cli/commands/search-replace/

== SECURITY NOTES ==

- Only users with the "manage_options" capability (Administrators) can access
  the backup page or trigger any backup/export/restore/delete/search-replace
  action.
- All AJAX actions are protected with a WordPress nonce.
- The backups folder blocks direct HTTP access at the web-server level.
- Restoring files rejects any ZIP entry containing ".." path segments and
  refuses to write into the plugin's own backup-storage folder, to prevent a
  crafted ZIP from overwriting files outside the intended destination.
- Uploaded files are validated by extension and, for ZIPs, by opening them
  with PHP's ZipArchive consistency check before anything is extracted.

== UNINSTALL ==

Deactivating/deleting the plugin does NOT delete your existing backup files
in wp-content/wpcb-backups/ — remove that folder manually via FTP/SFTP or
your host's file manager if you want to free up the disk space.

