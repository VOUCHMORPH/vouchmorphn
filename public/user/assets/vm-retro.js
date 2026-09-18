/* VouchMorph "Retro Shades" theme.
   Load AFTER the main dashboard <script> and BEFORE vm-motion.js. */
(function () {
    if (typeof THEMES === 'undefined' || typeof setTheme !== 'function') return;

    THEMES.retro = {
        '--bg': '#E3EDEA', '--surface': '#FFFBF0', '--surface-muted': '#F6EBD3',
        '--text': '#101A55', '--text-muted': '#2E3C7A', '--text-dim': '#5B6699',
        '--primary': '#0B0B0B', '--primary-dark': '#000000',
        '--accent': '#6A33E8', '--accent-2': '#F08DAA',
        '--border': 'rgba(11,11,11,0.35)', '--border-strong': '#0B0B0B'
    };

    function star(cx, cy, r1, r2, n) {
        const pts = [];
        for (let i = 0; i < n * 2; i++) {
            const r = i % 2 === 0 ? r1 : r2;
            const a = (Math.PI / n) * i - Math.PI / 2;
            pts.push((cx + r * Math.cos(a)).toFixed(1) + ',' + (cy + r * Math.sin(a)).toFixed(1));
        }
        return pts.join(' ');
    }

    const S = 'stroke="#161616" stroke-width="4.5" stroke-linejoin="round" stroke-linecap="round"';

    const GIRL = `
<symbol id="vmr-girl" viewBox="0 0 320 380"><g ${S}>
  <circle cx="70" cy="146" r="60" fill="#2A1810"/><circle cx="252" cy="144" r="60" fill="#2A1810"/>
  <circle cx="34" cy="214" r="48" fill="#2A1810"/><circle cx="288" cy="210" r="48" fill="#2A1810"/>
  <circle cx="44" cy="276" r="38" fill="#2A1810"/><circle cx="278" cy="274" r="38" fill="#2A1810"/>
  <g stroke="none" fill="#2A1810"><circle cx="70" cy="146" r="56"/><circle cx="252" cy="144" r="56"/><circle cx="34" cy="214" r="44"/><circle cx="288" cy="210" r="44"/><circle cx="44" cy="276" r="34"/><circle cx="278" cy="274" r="34"/></g>
  <path d="M26 160C32 136 46 122 64 116M296 158C290 134 276 120 258 114M8 222C10 204 18 194 30 188M312 218C310 200 302 190 290 184M20 286C22 272 28 264 38 260" fill="none" stroke="#7A5240" stroke-width="5"/>
  <path d="M56 380C56 320 94 290 160 290C226 290 262 320 266 380Z" fill="#2244D8"/>
  <g clip-path="url(#vmr-jk)" stroke-width="3.5">
    <path d="M52 296L120 318L98 384L40 384Z" fill="#6A33E8"/><path d="M198 296L270 312L276 384L228 384Z" fill="#14B8C4"/>
    <path d="M132 330L160 310L188 330L160 352Z" fill="#F2A12E"/><path d="M96 348L132 374L92 388Z" fill="#FFFBF0"/>
    <path d="M194 342L230 332L216 372Z" fill="#F08DAA"/><path d="M110 330L122 322L128 336Z" fill="#FFFBF0" stroke="none"/>
  </g>
  <path d="M108 292L160 330L134 348Z" fill="#14B8C4"/><path d="M212 292L160 330L186 348Z" fill="#F2A12E"/>
  <path d="M160 334V380" fill="none" stroke-width="6"/>
  <path d="M196 352L201 362L212 363L204 370L206 381L196 375L186 381L188 370L180 363L191 362Z" fill="#F2A12E" stroke-width="3"/>
  <path d="M138 258h44v38h-44z" fill="#C07A4F"/>
  <circle cx="62" cy="192" r="19" fill="#D39468"/><circle cx="260" cy="188" r="19" fill="#D39468"/>
  <path d="M160 74C236 70 270 120 264 182C259 244 216 280 160 280C98 280 58 244 57 184C55 118 90 78 160 74Z" fill="#D39468"/>
  <path d="M206 86C248 104 266 150 260 194C254 240 224 270 184 278C228 246 240 172 206 86Z" fill="#BF7F55" stroke="none"/>
  <circle cx="60" cy="220" r="15" fill="none" stroke="#F2A12E" stroke-width="6"/><circle cx="262" cy="216" r="15" fill="none" stroke="#F2A12E" stroke-width="6"/>
  <circle cx="104" cy="112" r="19" fill="#2A1810"/><circle cx="136" cy="102" r="15" fill="#2A1810"/><circle cx="194" cy="100" r="15" fill="#2A1810"/><circle cx="228" cy="112" r="19" fill="#2A1810"/>
  <path d="M80 118C82 54 124 26 164 26C208 26 244 56 244 118Z" fill="#9DB8CC"/>
  <path d="M120 34C104 54 100 86 102 118" fill="none" stroke="#BCD2E0" stroke-width="8"/>
  <path d="M164 30V58M110 50C130 44 198 44 220 52" fill="none" stroke-width="2.5" stroke-dasharray="5 5"/>
  <circle cx="164" cy="24" r="7" fill="#101A55"/>
  <path d="M124 54h76v44h-76z" fill="#FFFBF0" stroke-width="4"/>
  <path d="M162 90C138 74 134 62 145 58C154 56 159 62 162 67C166 62 170 56 180 58C191 62 186 74 162 90Z" fill="#F08DAA" stroke-width="3"/>
  <path d="M58 118C116 102 214 102 270 118C280 134 258 144 244 140C202 126 118 126 78 140C60 144 48 128 58 118Z" fill="#7F9DB3"/>
  <path d="M84 118C130 108 196 108 244 116" fill="none" stroke="#9DB8CC" stroke-width="5"/>
  <path d="M98 150C110 136 132 134 148 142M176 140C192 132 214 136 226 148" fill="none" stroke-width="5"/>
  <g transform="rotate(-7 162 172)">
    <path d="M84 166L74 158M84 176L70 174M242 164L252 156M242 174L256 172" fill="none" stroke-width="4"/>
    <ellipse cx="122" cy="172" rx="39" ry="21" fill="#F08DAA"/><ellipse cx="202" cy="172" rx="39" ry="21" fill="#F08DAA"/>
    <ellipse cx="122" cy="172" rx="28" ry="12.5" fill="#141414" stroke="none"/><ellipse cx="202" cy="172" rx="28" ry="12.5" fill="#141414" stroke="none"/>
    <path d="M158 168C161 163 163 163 166 168" fill="none"/>
    <ellipse cx="109" cy="167" rx="9" ry="3.5" fill="#fff" stroke="none"/><ellipse cx="189" cy="167" rx="7" ry="3" fill="#fff" stroke="none" opacity=".75"/>
  </g>
  <path d="M160 188C170 204 174 212 158 215" fill="none" stroke-width="4"/>
  <g stroke="none" fill="#8A4E2C"><circle cx="100" cy="204" r="3"/><circle cx="111" cy="211" r="3"/><circle cx="96" cy="215" r="3"/><circle cx="222" cy="202" r="3"/><circle cx="233" cy="209" r="3"/><circle cx="218" cy="213" r="3"/></g>
  <ellipse cx="98" cy="226" rx="16" ry="9" fill="#F08DAA" stroke="none" opacity=".6"/><ellipse cx="226" cy="224" rx="16" ry="9" fill="#F08DAA" stroke="none" opacity=".6"/>
  <path d="M118 226C136 266 190 266 210 224C190 236 140 238 118 226Z" fill="#7A2230"/>
  <path d="M127 231C146 237 182 237 200 229L197 239C180 244 148 244 131 240Z" fill="#fff" stroke-width="3"/>
  <path d="M164 235v7" stroke-width="2.5"/>
  <path d="M142 252C154 258 172 258 184 251" fill="none" stroke="#E0617E" stroke-width="6"/>
  <path d="M236 318C262 296 280 264 284 234L310 244C304 288 280 324 256 344Z" fill="#6A33E8"/>
  <path d="M262 312L284 296M270 330L292 312" fill="none" stroke="#FFFBF0" stroke-width="4"/>
  <circle cx="297" cy="224" r="19" fill="#D39468"/>
  <path d="M285 208L280 190M297 204L297 184M309 208L316 192" fill="none" stroke-width="5"/>
  <path d="M262 196C270 182 282 172 294 166M316 172C318 160 312 148 304 142" fill="none" stroke-width="3.5" stroke-dasharray="1 9"/>
</g></symbol>`;

    const GUY = `
<symbol id="vmr-guy" viewBox="0 0 200 220"><g ${S}>
  <path d="M34 220C38 190 66 176 100 176C134 176 162 190 166 220Z" fill="#14B8C4"/>
  <path d="M54 192L86 212L64 220Z" fill="#F2A12E"/><path d="M146 192L114 212L136 220Z" fill="#6A33E8"/>
  <path d="M100 178V220" fill="none" stroke-width="5"/>
  <circle cx="42" cy="118" r="17" fill="#F2C39A"/><circle cx="160" cy="116" r="17" fill="#F2C39A"/>
  <path d="M42 112C38 108 36 124 44 124" fill="none" stroke="#D99772" stroke-width="3"/>
  <path d="M100 36C154 36 168 90 162 128C156 166 130 184 100 184C66 184 44 166 40 128C36 86 50 36 100 36Z" fill="#F2C39A"/>
  <path d="M130 50C156 72 162 110 156 140C150 166 132 180 112 184C140 160 150 104 130 50Z" fill="#E4AE84" stroke="none"/>
  <path d="M48 72L54 20L74 50L86 4L102 46L120 2L128 48L150 18L154 76C124 58 80 58 48 72Z" fill="#E0562B"/>
  <path d="M86 12L96 40M120 10L122 40" fill="none" stroke="#F58A5E" stroke-width="4"/>
  <ellipse cx="78" cy="72" rx="19" ry="10" fill="#F08DAA"/><ellipse cx="122" cy="72" rx="19" ry="10" fill="#F08DAA"/>
  <ellipse cx="78" cy="72" rx="12" ry="5.5" fill="#141414" stroke="none"/><ellipse cx="122" cy="72" rx="12" ry="5.5" fill="#141414" stroke="none"/>
  <path d="M97 72h6" stroke-width="3"/>
  <ellipse cx="80" cy="110" rx="16" ry="20" fill="#fff"/><ellipse cx="122" cy="108" rx="16" ry="20" fill="#fff"/>
  <circle cx="85" cy="115" r="6.5" fill="#161616"/><circle cx="117" cy="113" r="6.5" fill="#161616"/>
  <circle cx="87" cy="112" r="2" fill="#fff" stroke="none"/><circle cx="119" cy="110" r="2" fill="#fff" stroke="none"/>
  <circle cx="102" cy="136" r="11" fill="#F5A98A"/>
  <path d="M70 152C86 176 122 176 136 150C120 160 88 162 70 152Z" fill="#7A2230"/>
  <path d="M93 157h9v12h-9zM102 157h9v12h-9z" fill="#fff" stroke-width="2.5"/>
  <g stroke="none" fill="#C9683F"><circle cx="60" cy="134" r="2.8"/><circle cx="67" cy="141" r="2.8"/><circle cx="140" cy="132" r="2.8"/><circle cx="133" cy="139" r="2.8"/></g>
  <circle cx="30" cy="206" r="15" fill="#F2C39A"/><circle cx="170" cy="206" r="15" fill="#F2C39A"/>
  <path d="M22 198v14M30 196v16M38 198v14M162 198v14M170 196v16M178 198v14" fill="none" stroke-width="2.5"/>
</g></symbol>`;

    const SCENE = `
<svg class="vmr-scene" viewBox="0 0 800 380" preserveAspectRatio="xMidYMax slice" aria-hidden="true">
  <rect width="800" height="380" fill="#FFE3A8"/>
  <polygon points="${star(470, 78, 70, 48, 14)}" fill="#F2A12E" stroke="#161616" stroke-width="4" stroke-linejoin="round"/>
  <circle cx="470" cy="78" r="40" fill="#FFD166" stroke="#161616" stroke-width="4"/>
  <path d="M600 40c0-14 20-18 28-8c8-12 32-10 32 4c14 0 16 16 4 18h-60c-14 0-14-14-4-14z" fill="#fff" stroke="#161616" stroke-width="4" stroke-linejoin="round"/>
  <path d="M330 150l10 8l10-8M372 128l8 7l8-7" fill="none" stroke="#161616" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>
  <path d="M0 280q25-18 50 0t50 0t50 0t50 0t50 0t50 0t50 0t50 0t50 0t50 0t50 0t50 0t50 0t50 0t50 0t50 0V380H0Z" fill="#5FA3AA" stroke="#161616" stroke-width="5"/>
  <path d="M40 320q14-9 28 0M180 346q14-9 28 0M320 314q14-9 28 0M110 362q14-9 28 0M460 336q14-9 28 0" fill="none" stroke="#FFFBF0" stroke-width="5" stroke-linecap="round"/>
</svg>`;

    document.body.insertAdjacentHTML('afterbegin',
        `<svg width="0" height="0" style="position:absolute" aria-hidden="true"><defs><clipPath id="vmr-jk"><path d="M56 380C56 320 94 290 160 290C226 290 262 320 266 380Z"/></clipPath>${GIRL}${GUY}</defs></svg>`);

    const hub = document.getElementById('hubView');
    if (hub && !hub.querySelector('.vmr-hero')) {
        hub.insertAdjacentHTML('afterbegin', `
<div class="vmr-hero">
  ${SCENE}
  <div class="vmr-hero-text"><div class="vmr-eyebrow">VouchMorph</div><div class="vmr-title">What do you want to do?</div></div>
  <svg class="vmr-burst" viewBox="0 0 400 400" aria-hidden="true">
    <polygon points="${star(200, 200, 196, 150, 18)}" fill="#F08DAA" stroke="#161616" stroke-width="5" stroke-linejoin="round"/>
    <polygon points="${star(200, 200, 146, 112, 18)}" fill="#6A33E8" stroke="#161616" stroke-width="5" stroke-linejoin="round"/>
  </svg>
  <svg class="vmr-guy" viewBox="0 0 200 220" aria-hidden="true"><use href="#vmr-guy"/></svg>
  <svg class="vmr-girl" viewBox="0 0 320 380" aria-hidden="true"><use href="#vmr-girl"/></svg>
  <div class="vmr-bubble">Let's move some money!</div>
</div>`);
    }

    const peeks = { swapView: 'guy', cardView: 'girl', activityView: 'guy', toolboxView: 'girl', hookView: 'guy' };
    Object.entries(peeks).forEach(([viewId, who]) => {
        const header = document.querySelector('#' + viewId + ' .product-view-header');
        if (!header || header.querySelector('.vmr-peek')) return;
        const svg = who === 'guy'
            ? `<svg class="vmr-peek guy" viewBox="0 0 200 200" aria-hidden="true"><use href="#vmr-guy"/></svg>`
            : `<svg class="vmr-peek girl" viewBox="30 10 270 270" aria-hidden="true"><use href="#vmr-girl" width="320" height="380"/></svg>`;
        header.insertAdjacentHTML('beforeend', svg);
    });

    const originalSetTheme = setTheme;
    window.setTheme = function (name) {
        document.body.classList.toggle('theme-retro', name === 'retro');
        originalSetTheme(name);
    };

    const CHIP = `<div class="theme-chip" data-theme="retro" onclick="setTheme('retro')"><div class="swatch"><div class="dot" style="background:#0B0B0B;"></div><div class="dot" style="background:#F08DAA;"></div><div class="dot" style="background:#14B8C4;"></div></div>Retro Shades</div>`;
    function addChip() {
        const picker = document.querySelector('#toolboxViewBody .theme-picker');
        if (!picker || picker.querySelector('[data-theme="retro"]')) return;
        picker.insertAdjacentHTML('beforeend', CHIP);
        let current = 'classic';
        try { current = localStorage.getItem('vm_theme') || 'classic'; } catch (e) {}
        picker.querySelectorAll('.theme-chip').forEach(c => c.classList.toggle('active', c.dataset.theme === current));
    }
    const toolboxBody = document.getElementById('toolboxViewBody');
    if (toolboxBody) new MutationObserver(addChip).observe(toolboxBody, { childList: true, subtree: true });
})();
