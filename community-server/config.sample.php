<?php
// PSGFX community icons - settings. Copy this file to config.php and fill it in.
// config.php holds your GitHub key: never share it or put it on GitHub.

// The GitHub repository the icons are saved to, as "owner/name". It must be public.
const GITHUB_REPO = 'elripalda/psgfx-community';
const GITHUB_BRANCH = 'main';

// A fine-grained personal access token with access to ONLY that repository,
// permission "Contents: Read and write". See SETUP.md.
const GITHUB_TOKEN = 'github_pat_PASTE_YOURS_HERE';

// Password for mod.php, where you review and delete icons. Pick a long one.
const ADMIN_PASSWORD = 'change-me-to-something-long';

// Limits per person (by IP address).
const UPLOADS_PER_HOUR = 10;
const UPLOADS_PER_DAY = 30;
// An icon is hidden after this many different people report it, until you look at it in mod.php.
const REPORTS_TO_HIDE = 3;
// Largest file accepted, in bytes.
const MAX_UPLOAD_BYTES = 6 * 1024 * 1024;
