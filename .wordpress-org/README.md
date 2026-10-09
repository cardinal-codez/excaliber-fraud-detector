# wordpress.org listing assets

These files are copied to the plugin's SVN `/assets/` directory (NOT `/trunk/`)
after the plugin is approved. They are never shipped inside the plugin zip —
`.gitattributes` (`.* export-ignore`) keeps this folder out of releases.

**None of these files exist yet.** `readme.txt` describes three screenshots, so
capture them from a running install of the current build before uploading
assets — the screens must show the **HIFQA Fraud Detector** name.

Required files (numbering must match the `== Screenshots ==` section in readme.txt):

| File               | Content                                                    |
|--------------------|------------------------------------------------------------|
| screenshot-1.png   | Orders dashboard (flagged orders table with risk scores)   |
| screenshot-2.png   | Signal Weights tab (weight personalization)                |
| screenshot-3.png   | Settings page (AI provider selection)                      |

Optional but recommended:

| File               | Size            |
|--------------------|-----------------|
| icon-256x256.png   | 256×256         |
| icon-128x128.png   | 128×128         |
| banner-1544x500.png| 1544×500 (hi-dpi)|
| banner-772x250.png | 772×250         |
