# Website fonts — sources & licences

Self-hosted web fonts of the public website (no third-party font requests, so no visitor data goes to a font CDN).
Inter (400–700) and Plus Jakarta Sans (500–800) are the standard design fonts declared in `assets/css/src/style.css`.
The others are the choices offered in Admin › Settings › Website › Brand colours & fonts (`app/services/site_brand.php`
prints their `@font-face` rules only when chosen).

All files are the `latin` subset (plus `devanagari` for Mukta) as WOFF2, from the `@fontsource` npm packages
(`https://cdn.jsdelivr.net/npm/@fontsource/<family>@5.3.0/files/<family>-<subset>-<weight>-normal.woff2`), downloaded 2026-10-26.

| Family | Package | Weights | Licence |
|---|---|---|---|
| Plus Jakarta Sans | @fontsource/plus-jakarta-sans 5.3.0 | 400 (500–800 bundled earlier) | SIL OFL 1.1 |
| Poppins | @fontsource/poppins 5.3.0 | 400, 500, 600, 700, 800 | SIL OFL 1.1 |
| Montserrat | @fontsource/montserrat 5.3.0 | 400, 500, 600, 700, 800 | SIL OFL 1.1 |
| DM Sans | @fontsource/dm-sans 5.3.0 | 400, 500, 600, 700, 800 | SIL OFL 1.1 |
| Manrope | @fontsource/manrope 5.3.0 | 400, 500, 600, 700, 800 | SIL OFL 1.1 |
| Outfit | @fontsource/outfit 5.3.0 | 400, 500, 600, 700, 800 | SIL OFL 1.1 |
| Nunito Sans | @fontsource/nunito-sans 5.3.0 | 400, 500, 600, 700, 800 | SIL OFL 1.1 |
| Open Sans | @fontsource/open-sans 5.3.0 | 400, 500, 600, 700, 800 | SIL OFL 1.1 |
| Lato | @fontsource/lato 5.3.0 | 400, 700, 900 | SIL OFL 1.1 |
| Roboto | @fontsource/roboto 5.3.0 | 400, 500, 700, 900 | SIL OFL 1.1 |
| Mukta (Latin + Devanagari) | @fontsource/mukta 5.3.0 | 400, 500, 600, 700, 800 | SIL OFL 1.1 |

The SIL Open Font License allows bundling and self-hosting with a website; the fonts may not be sold on their own.
