# Legacy Bootstrap styles and tabs

These files are a subset of [Bootstrap v3.4.1](https://github.com/twbs/bootstrap/tree/v3.4.1), distributed with its upstream license. Only whitespace in the license and SVG font was normalized. The assets preserve the styling and tabs of the legacy calendar UI without installing the full Bootstrap 3 npm package.

Only the imported LESS sources, their mixins, the bundled Glyphicon fonts and `js/tab.js` are kept. The button, tooltip and popover JavaScript is neither vendored nor bundled. These are the plugins affected by [CVE-2024-6485](https://github.com/advisories/GHSA-vxmc-5x29-h64v) and [CVE-2025-1647](https://github.com/advisories/GHSA-q58r-hwc8-rm9j).

Source archive: `https://registry.npmjs.org/bootstrap/-/bootstrap-3.4.1.tgz`

Integrity (from the previous npm lockfile): `sha512-yN5oZVmRCwe5aKwzRj6736nSmKDX7pLYwsXiCj/EYmo16hODaBiT4En5btW/jhBF/seV+XMx3aYwukYC3A49DA==`

Do not replace the subset with the full distribution. Run `npm run build:assets`, `npm run test:frontend` and `npm run audit` after updating the assets.
