# Get External Images

**Get External Images** is a lightweight WordPress plugin that scans published posts for externally hosted images, downloads them to your Media Library, and automatically updates the image URLs in post content.

This is especially useful after migrating from another CMS or website, where posts may still reference images hosted on an external domain.

✅ Skips images already hosted locally<br>
✅ Ignores draft posts<br>
✅ Includes basic SSRF protection for added safety<br>
✅ Tested with WordPress 6.8

## Before You Start

Please make a full backup of your database before running the import. There are many great backup plugins available in the WordPress plugin ecosystem.

## How to Use

- Install and activate the plugin
- Go to **Get External Images** in the WordPress admin panel
- Click the "Start import" button
- Watch the progress bar as your posts are scanned and images are imported.

Once complete, all external image URLs found in published posts will be replaced with links to copies in your own Media Library.

## Notes

- Only published posts are processed
- The plugin runs in small batches via AJAX for better performance and to avoid timeouts.
