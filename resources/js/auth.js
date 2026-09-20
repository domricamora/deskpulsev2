/**
 * Behaviour for the sign-up and reset-password forms.
 *
 * Ported from the inline <script> blocks in templates/auth/register.php and
 * templates/auth/reset_password.php. Moved out of the markup because the
 * Content-Security-Policy is due to drop 'unsafe-inline' (decision D13); the
 * behaviour is unchanged.
 *
 * Both features feature-detect their elements, so this module is safe to load on
 * every page.
 */

/** Show/hide password. Never block paste — it pushes people to weaker passwords. */
function passwordToggle() {
    const input = document.getElementById('dp-pass');
    const toggle = document.getElementById('dp-pass-toggle');

    if (!input || !toggle) {
        return;
    }

    toggle.addEventListener('click', () => {
        const show = input.type === 'password';

        input.type = show ? 'text' : 'password';
        toggle.textContent = show ? 'Hide' : 'Show';
        toggle.setAttribute('aria-label', `${show ? 'Hide' : 'Show'} password`);
        input.focus();
    });
}

/** The handful of typos that account for most bounced signup emails. */
const TYPOS = {
    'gmial.com': 'gmail.com',
    'gmai.com': 'gmail.com',
    'gmail.co': 'gmail.com',
    'gnail.com': 'gmail.com',
    'hotmial.com': 'hotmail.com',
    'hotmai.com': 'hotmail.com',
    'yahooo.com': 'yahoo.com',
    'yaho.com': 'yahoo.com',
    'outlok.com': 'outlook.com',
    'outloo.com': 'outlook.com',
    'iclod.com': 'icloud.com',
};

function emailTypoHint() {
    const email = document.getElementById('dp-email');
    const hint = document.getElementById('dp-email-hint');

    if (!email || !hint) {
        return;
    }

    email.addEventListener('blur', () => {
        const parts = email.value.split('@');
        const fix = parts.length === 2 ? TYPOS[parts[1].toLowerCase()] : null;

        if (!fix) {
            hint.hidden = true;
            return;
        }

        const corrected = `${parts[0]}@${fix}`;

        // Built as nodes rather than innerHTML so the address, which came from
        // the person typing, can never be markup.
        hint.replaceChildren();
        hint.append('Did you mean ');

        const suggestion = document.createElement('b');
        suggestion.textContent = corrected;
        suggestion.style.cursor = 'pointer';
        suggestion.style.textDecoration = 'underline';
        suggestion.addEventListener('click', () => {
            email.value = corrected;
            hint.hidden = true;
        });

        hint.append(suggestion, '?');
        hint.hidden = false;
    });
}

passwordToggle();
emailTypoHint();
