# Community icons: setting up the server

PSGFX 2.0 lets people share icons with each other. Uploads go to a small PHP script on your own
website, which checks each image and saves it to a public GitHub repository. Your GitHub key stays
on your website, so it never ships inside PSGFX.

**Cost:** nothing. It runs on the web hosting plan you already have (any plan with PHP 8,
including Hostinger's) and a free GitHub repository.

## 1. Make the icons repository on GitHub

1. Go to <https://github.com/new>.
2. Name it `psgfx-community`, set it to **Public**, and tick **Add a README file**.
3. Click **Create repository**.

## 2. Make a key that can only touch that repository

1. Go to <https://github.com/settings/personal-access-tokens/new>.
2. **Token name:** `psgfx-community upload`. **Expiration:** the longest it offers. Put a reminder in
   your calendar to make a new one before it runs out (uploads stop working when it expires).
3. **Repository access:** *Only select repositories* → `psgfx-community`.
4. **Permissions** → **Repository permissions** → **Contents** → **Read and write**.
5. Click **Generate token** and copy it (it starts with `github_pat_`). You only see it once.

## 3. Put the script on your website (Hostinger)

1. In hPanel open **Websites** → your site → **File Manager**.
2. Open `public_html` and create a folder called `psgfx`.
3. Open `psgfx` and upload everything from `psgfx-community-server.zip`, then extract it there
   (right-click the zip → **Extract**, into the current folder). Delete the zip afterwards.
4. Rename `config.sample.php` to `config.php`, open it, and fill in:
   - `GITHUB_TOKEN`: the key from step 2.
   - `ADMIN_PASSWORD`: a long password for the moderation page.
   - `GITHUB_REPO` only if you used a different name than `elripalda/psgfx-community`.
5. Save.

## 4. Check it

Open `https://YOUR-DOMAIN/psgfx/list.php?title=PPSA99764` in your browser. You should see:

```
{"ok":true,"icons":[]}
```

Then send the address (`https://YOUR-DOMAIN/psgfx/`; PSGFX 2.0 is built for `https://ps5gfx.elripalda.com/`) so it can be built into PSGFX. Until then you can
try it yourself by adding `"community": "https://YOUR-DOMAIN/psgfx/"` to `psgfx.json` next to PSGFX.exe.

## Moderating

Open `https://YOUR-DOMAIN/psgfx/mod.php` and log in with `ADMIN_PASSWORD`.

- Every shared icon is listed, newest first. Reported ones come first.
- An icon is hidden automatically after 3 different people report it, until you **Keep** or **Delete** it.
- **Delete** removes it from GitHub and your site, so it disappears from PSGFX within a minute or two.
- **Delete + block** also stops whoever uploaded it from uploading again.
- You can also delete files straight on GitHub; PSGFX follows whatever is in the repository.

Limits per person are 10 uploads an hour and 30 a day. Change them in `config.php`.

## What's where

| File | What it does |
| --- | --- |
| `upload.php` | Receives an icon from PSGFX, checks it, makes a clean 512 × 512 PNG and a 128 × 128 thumbnail, saves both to GitHub, and keeps a copy in `files/` on your site. |
| `list.php?title=ID` | The icons for one game, newest first. |
| `index.php` | Every game and its icons in one file, for apps that show the whole library. |
| `report.php` | Takes a report about an icon. |
| `mod.php` | Your moderation page. |
| `data/` | Upload limits, reports and blocks. Not reachable from the web. |
| `files/` | Copies of every icon and thumbnail, served by your site. |

In the GitHub repository icons are saved as `icons/<title ID>/<time>_<hash>_<name>.png`, with the
thumbnail at the same name under `thumbs/`.
