"""Computed visual checks for the optional, disposable-database Edge harness.

No page text, form values, accessible names, URLs, or credentials are returned.
Only structural CSS selectors, numeric measurements, and fixed issue types leave
the browser. This supplements screenshot review; it is not an accessibility
certification or a pixel-level analysis of overlapping content.
"""
from __future__ import annotations


VISUAL_QUALITY = r'''(() => {
    const transparent = [0, 0, 0, 0], canvas = [1, 1, 1, 1];
    const cache = new WeakMap();
    const style = node => {
        if (!cache.has(node)) cache.set(node, getComputedStyle(node));
        return cache.get(node);
    };
    const rounded = value => Number.isFinite(value) ? Math.round(value * 1000) / 1000 : null;
    const selector = node => {
        const parts = [];
        for (let item = node; item?.nodeType === 1; item = item.parentElement) {
            const tag = item.localName.toLowerCase();
            const classes = [...item.classList].filter(name => /^[a-z_][a-z0-9_-]{0,40}$/i.test(name)).slice(0, 2);
            let segment = tag + classes.map(name => '.' + CSS.escape(name)).join('');
            if (item.parentElement) {
                const peers = [...item.parentElement.children].filter(peer => peer.localName === item.localName);
                if (peers.length > 1) segment += ':nth-of-type(' + (peers.indexOf(item) + 1) + ')';
            }
            parts.unshift(segment);
            if (tag === 'body') break;
        }
        return parts.join(' > ');
    };
    const result = {
        method: 'WCAG sRGB luminance; alpha compositing of element backgrounds and ancestor opacity',
        textContrast: null, controlContrast: null, placeholderContrast: null,
        iconContrast: null, controlBoundaries: null, fontReadability: null,
        buttonMediaBounds: null, limitations: [], limitationCount: 0,
        scope: 'Rendered document including below-fold content; inactive, hidden, inert and screen-reader-only content excluded',
    };
    const limitationKeys = new Set();
    const limitation = (node, type) => {
        const path = selector(node), key = type + '|' + path;
        if (limitationKeys.has(key)) return;
        limitationKeys.add(key);
        result.limitationCount++;
        if (result.limitations.length < 10) result.limitations.push({selector: path, type});
    };
    const group = () => ({checked: 0, failureCount: 0, unresolved: 0, minRatio: null, failures: []});
    for (const key of ['textContrast', 'controlContrast', 'placeholderContrast', 'iconContrast', 'controlBoundaries']) result[key] = group();
    result.textContrast.normalText = 0;
    result.textContrast.largeText = 0;
    result.fontReadability = {checked: 0, failureCount: 0, minFontPx: null, failures: []};
    result.buttonMediaBounds = {checked: 0, failureCount: 0, failures: []};
    const failure = (bucket, node, type, data) => {
        bucket.failureCount++;
        if (bucket.failures.length < 10) bucket.failures.push({selector: selector(node), type, ...data});
    };
    const color = value => {
        value = String(value || '').trim().toLowerCase();
        if (value === 'transparent') return [...transparent];
        const match = value.match(/^(rgba?|color)\((.*)\)$/);
        if (!match) return null;
        let content = match[2], unit = 255;
        if (match[1] === 'color') {
            if (!/^srgb\s/.test(content)) return null;
            content = content.replace(/^srgb\s+/, '');
            unit = 1;
        }
        const bits = content.replace(/[,/]/g, ' ').trim().split(/\s+/);
        if (bits.length < 3 || bits.length > 4) return null;
        const clamp = number => Math.min(1, Math.max(0, number));
        const channels = bits.slice(0, 3).map(bit => clamp(parseFloat(bit) / (bit.endsWith('%') ? 100 : unit)));
        const alpha = bits[3] === undefined ? 1 : clamp(parseFloat(bits[3]) / (bits[3].endsWith('%') ? 100 : 1));
        return [...channels, alpha].every(Number.isFinite) ? [...channels, alpha] : null;
    };
    const over = (front, back) => {
        const alpha = front[3] + back[3] * (1 - front[3]);
        if (!alpha) return [...transparent];
        return [0, 1, 2].map(index => (front[index] * front[3] + back[index] * back[3] * (1 - front[3])) / alpha).concat(alpha);
    };
    const luminance = rgba => rgba.slice(0, 3).map(channel => channel <= .04045 ? channel / 12.92 : ((channel + .055) / 1.055) ** 2.4)
        .reduce((sum, channel, index) => sum + channel * [.2126, .7152, .0722][index], 0);
    const contrast = (front, back) => {
        const a = luminance(front), b = luminance(back);
        return (Math.max(a, b) + .05) / (Math.min(a, b) + .05);
    };
    // Work from the inner layer outward. Applying opacity after the element's
    // contents and background are combined preserves nested group opacity.
    const paint = (node, ink, extraOpacity = 1) => {
        if (!ink) {
            limitation(node, 'unresolved-css-color');
            return null;
        }
        let pixel = [...ink];
        pixel[3] *= extraOpacity;
        for (let item = node; item; item = item.parentElement) {
            const cs = style(item);
            if ((cs.filter && cs.filter !== 'none') || cs.mixBlendMode !== 'normal') {
                limitation(item, 'filter-or-blend-paint');
                return null;
            }
            if (pixel[3] < .999999 && cs.backgroundImage !== 'none') {
                limitation(item, 'image-or-gradient-background');
                return null;
            }
            if (pixel[3] < .999999 && cs.backdropFilter && cs.backdropFilter !== 'none') {
                limitation(item, 'backdrop-filter-background');
                return null;
            }
            const background = color(cs.backgroundColor);
            if (!background) {
                limitation(item, 'unresolved-css-background-color');
                return null;
            }
            pixel = over(pixel, background);
            pixel[3] *= Number(cs.opacity);
        }
        return over(pixel, canvas);
    };
    const excluded = 'script,style,noscript,template,[hidden],[inert],.sr-only,.visually-hidden';
    const visible = (node, iconRoot = null) => {
        if (!node?.isConnected || node.closest(excluded)) return false;
        if (node.matches(':disabled,[aria-disabled="true"]') || node.closest('button:disabled,[aria-disabled="true"]')) return false;
        const cs = style(node);
        if (cs.visibility !== 'visible' || cs.display === 'none' || cs.contentVisibility === 'hidden') return false;
        for (let item = node; item; item = item.parentElement) {
            const own = style(item);
            if (Number(own.opacity) === 0 || own.display === 'none' || own.contentVisibility === 'hidden') return false;
            if (item.getAttribute('aria-hidden') === 'true' && !(iconRoot && (item === iconRoot || iconRoot.contains(item)))) return false;
            if (own.clip !== 'auto' && /^rect\(0px[, ]+0px[, ]+0px[, ]+0px\)$/.test(own.clip)) return false;
            if (/^inset\(50%/.test(own.clipPath)) return false;
        }
        return [...node.getClientRects()].some(box => box.width > 0 && box.height > 0 && box.right > 0 && box.bottom > 0);
    };
    const inspectRatio = (bucket, node, type, ink, threshold, options = {}) => {
        const front = paint(node, ink, options.opacity ?? 1);
        const back = paint(options.adjacent ? node.parentElement : node, transparent);
        if (!front || !back) {
            bucket.unresolved++;
            return;
        }
        const ratio = contrast(front, back);
        bucket.checked++;
        bucket.minRatio = bucket.minRatio === null ? ratio : Math.min(bucket.minRatio, ratio);
        if (ratio + .000001 < threshold) failure(bucket, node, type, {ratio: rounded(ratio), threshold});
    };
    const fontSeen = new WeakSet();
    const inspectFont = (node, cs = style(node), forceFunctional = false) => {
        if (fontSeen.has(node)) return;
        fontSeen.add(node);
        const size = parseFloat(cs.fontSize);
        const category = !forceFunctional && node.matches('.eyebrow,.nav-group-label,.brand-name small');
        const threshold = category ? 11 : 12;
        const bucket = result.fontReadability;
        bucket.checked++;
        bucket.minFontPx = bucket.minFontPx === null ? size : Math.min(bucket.minFontPx, size);
        if (size + .01 < threshold) failure(bucket, node, category ? 'category-font' : 'functional-font', {fontPx: rounded(size), threshold});
    };
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    let text;
    while ((text = walker.nextNode())) {
        const node = text.parentElement;
        // Native values/options are inspected using the control's own styles.
        if (!text.nodeValue.trim() || !node || node.closest('input,select,textarea,svg') || !visible(node)) continue;
        const range = document.createRange();
        range.selectNodeContents(text);
        const rendered = [...range.getClientRects()].some(box => box.width > 0 && box.height > 0 && box.right > 0 && box.bottom > 0);
        range.detach();
        if (!rendered) continue;
        const cs = style(node), ink = color(cs.webkitTextFillColor || cs.color);
        if (ink && ink[3] === 0) continue;
        const size = parseFloat(cs.fontSize), weight = Number(cs.fontWeight) || (cs.fontWeight === 'bold' ? 700 : 400);
        const large = size >= 24 || (size >= 18.666666 && weight >= 700);
        result.textContrast[large ? 'largeText' : 'normalText']++;
        inspectRatio(result.textContrast, node, large ? 'large-text' : 'normal-text', ink, large ? 3 : 4.5);
        inspectFont(node, cs);
    }
    for (const node of document.querySelectorAll('input,select,textarea')) {
        if (!visible(node) || node.type === 'hidden') continue;
        const cs = style(node), type = (node.type || '').toLowerCase();
        if (['checkbox', 'radio', 'range', 'color', 'file', 'image'].includes(type)) {
            limitation(node, 'native-nontext-control-parts');
            result.controlBoundaries.unresolved++;
            continue;
        }
        // Styles are checked without reading value, selected text or password.
        inspectRatio(result.controlContrast, node, 'control-text', color(cs.webkitTextFillColor || cs.color), 4.5);
        inspectFont(node, cs, true);
        if (node.hasAttribute('placeholder')) {
            const placeholder = getComputedStyle(node, '::placeholder');
            inspectRatio(result.placeholderContrast, node, 'placeholder-text', color(placeholder.webkitTextFillColor || placeholder.color), 4.5, {opacity: Number(placeholder.opacity)});
            const size = parseFloat(placeholder.fontSize);
            result.fontReadability.checked++;
            result.fontReadability.minFontPx = result.fontReadability.minFontPx === null ? size : Math.min(result.fontReadability.minFontPx, size);
            if (size + .01 < 12) failure(result.fontReadability, node, 'placeholder-font', {fontPx: rounded(size), threshold: 12});
        }
        const sides = ['Top', 'Right', 'Bottom', 'Left'];
        let anyBorder = false;
        for (const side of sides) {
            if (parseFloat(cs['border' + side + 'Width']) <= 0 || ['none', 'hidden'].includes(cs['border' + side + 'Style'])) continue;
            anyBorder = true;
            inspectRatio(result.controlBoundaries, node, 'control-border-' + side.toLowerCase(), color(cs['border' + side + 'Color']), 3, {adjacent: true});
        }
        if (!anyBorder) {
            // A contrasting control surface can identify an otherwise borderless field.
            inspectRatio(result.controlBoundaries, node, 'control-surface-boundary', transparent, 3, {adjacent: true});
        }
    }
    for (const icon of document.querySelectorAll('svg,img,[role="img"]')) {
        const owner = icon.closest('a[href],button,[role="button"]');
        if (!owner || !visible(icon, icon)) continue;
        if (icon.localName !== 'svg') {
            limitation(icon, 'raster-or-non-svg-icon-pixels');
            result.iconContrast.unresolved++;
            continue;
        }
        let samples = 0;
        for (const shape of icon.querySelectorAll('path,rect,circle,ellipse,line,polyline,polygon,use,text')) {
            if (shape.closest('defs,clipPath,mask,symbol') || style(shape).visibility !== 'visible' || style(shape).display === 'none') continue;
            const cs = style(shape);
            for (const kind of ['stroke', 'fill']) {
                if (cs[kind] === 'none' || (kind === 'stroke' && parseFloat(cs.strokeWidth) <= 0)) continue;
                const ink = color(cs[kind]);
                if (ink && ink[3] === 0) continue;
                inspectRatio(result.iconContrast, shape, 'functional-icon-' + kind, ink, 3, {opacity: Number(cs[kind + 'Opacity'])});
                samples++;
            }
        }
        if (!samples) {
            limitation(icon, 'svg-without-resolved-painted-shapes');
            result.iconContrast.unresolved++;
        }
    }
    for (const button of document.querySelectorAll('button,a.button,[role="button"]')) {
        if (!visible(button)) continue;
        const outer = button.getBoundingClientRect(), cs = style(button);
        const bounds = {
            left: outer.left + parseFloat(cs.borderLeftWidth),
            right: outer.right - parseFloat(cs.borderRightWidth),
            top: outer.top + parseFloat(cs.borderTopWidth),
            bottom: outer.bottom - parseFloat(cs.borderBottomWidth),
        };
        for (const icon of button.querySelectorAll('svg,img')) {
            if (!visible(icon, icon)) continue;
            const box = icon.getBoundingClientRect(), bucket = result.buttonMediaBounds;
            bucket.checked++;
            const overflow = Math.max(0, bounds.left - box.left, box.right - bounds.right, bounds.top - box.top, box.bottom - bounds.bottom);
            if (overflow > 1) failure(bucket, icon, 'button-media-overflow', {overflowPx: rounded(overflow)});
        }
    }
    for (const key of ['textContrast', 'controlContrast', 'placeholderContrast', 'iconContrast', 'controlBoundaries']) result[key].minRatio = rounded(result[key].minRatio);
    result.fontReadability.minFontPx = rounded(result.fontReadability.minFontPx);
    result.resolved = ['textContrast', 'controlContrast', 'placeholderContrast', 'iconContrast', 'controlBoundaries'].every(key => !result[key].unresolved);
    return result;
})()'''


def inspect_visual_quality(browser, check, label: str, viewport: str) -> dict:
    """Inspect the loaded page and append compact checks to the caller's report.

    Unresolved paint stacks are explicitly reported, never included in passing
    contrast counts. The separate resolved-paint check prevents unknown surfaces
    from silently producing a complete contrast pass.
    """
    metrics = browser.evaluate(VISUAL_QUALITY)
    prefix = viewport + ' ' + label
    checks = (
        ('textContrast', 'computed text contrast'),
        ('controlContrast', 'control text contrast'),
        ('placeholderContrast', 'placeholder contrast'),
        ('iconContrast', 'functional icon contrast'),
        ('controlBoundaries', 'control boundary contrast'),
        ('fontReadability', 'readable functional font sizes'),
        ('buttonMediaBounds', 'button images and icons fit'),
    )
    for key, name in checks:
        bucket = metrics[key]
        check(prefix + ' ' + name, bucket['failureCount'] == 0, **bucket)
    check(prefix + ' resolved contrast paint stacks', metrics['resolved'],
          limitation_count=metrics['limitationCount'], limitations=metrics['limitations'],
          unresolved={key: metrics[key]['unresolved'] for key, _ in checks
                      if 'unresolved' in metrics[key]})
    return metrics
