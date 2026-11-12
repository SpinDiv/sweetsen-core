<?php

declare(strict_types=1);

namespace SweetSen\Portal;

use SweetSen\Domain\Registrations;
use SweetSen\Support\Db;

/**
 * Website forms: [sweetsen_senior_form], [sweetsen_sponsor_form] and [sweetsen_contact_form]. They post to the public REST
 * routes; every request waits for LGU review in the console before anything is created.
 */
final class PublicForms
{
    public static function register(): void
    {
        add_shortcode('sweetsen_senior_form', [self::class, 'senior']);
        add_shortcode('sweetsen_sponsor_form', [self::class, 'sponsor']);
        add_shortcode('sweetsen_contact_form', [self::class, 'contact']);
    }

    /** A closed, not-yet-open or full form shows a plain notice in its place. These pages are never cached: the state changes by date and by count. */
    private static function gate(string $kind): ?string
    {
        do_action('litespeed_control_set_nocache', 'SweetSen form state');
        try {
            $s = \SweetSen\Domain\FormLimits::state(Registrations::publicTenant(), $kind);
        } catch (\Throwable $e) {
            return null;
        }
        if ($s['open']) {
            return null;
        }
        $head = match ($s['reason']) { 'full' => 'Come back tomorrow', 'not_yet' => 'Not open yet', default => 'Closed for now' };
        return self::css() . '<div class="ssf-wrap"><div class="ssf-closed" role="status"><p class="ssf-closed-h">' . esc_html($head) . '</p><p>' . esc_html($s['message']) . '</p>'
            . ($kind !== 'inquiry' ? '<p><a href="' . esc_url(home_url('/contact/')) . '">Questions? Contact us.</a></p>' : '') . '</div></div>';
    }

    public static function senior(): string
    {
        if (($closed = self::gate('senior')) !== null) {
            return $closed;
        }
        $opts = '';
        try {
            $t = Registrations::publicTenant();
            foreach (Db::rows(Db::prepare('SELECT DISTINCT barangay FROM ' . Db::t('beneficiaries') . ' WHERE tenant_id = UNHEX(%s) ORDER BY barangay', $t)) as $b) {
                $opts .= '<option value="' . esc_attr($b['barangay']) . '">';
            }
        } catch (\Throwable $e) {
            return '<p>Registration is not available right now.</p>';
        }
        $sfx = '';
        foreach (['', 'Jr.', 'Sr.', 'II', 'III', 'IV'] as $s) {
            $sfx .= '<option value="' . esc_attr($s) . '">' . ($s === '' ? 'None' : esc_html($s)) . '</option>';
        }
        $max = Db::todayManila()->modify('-60 years')->format('Y-m-d');
        $form = '<form class="ssf" data-route="public/senior-request" novalidate>'
            . self::demoNote()
            . '<fieldset><legend>Who is filling in this form?</legend>'
            . '<label class="ssf-opt"><input type="radio" name="filed_by" value="self" checked> I am the senior</label>'
            . '<label class="ssf-opt"><input type="radio" name="filed_by" value="family"> I am a family member or helper</label></fieldset>'
            . '<div class="ssf-family" hidden><div class="ssf-row">'
            . self::field('filer_name', 'Your name', 'text', ['autocomplete' => 'name'])
            . self::field('filer_relation', 'How are you related?', 'text', ['placeholder' => 'For example: daughter, neighbor'])
            . '</div></div>'
            . '<h3>About the senior</h3><div class="ssf-row">'
            . self::field('first_name', 'First name', 'text', ['required' => true, 'autocomplete' => 'given-name'])
            . self::field('middle_name', 'Middle name', 'text', ['autocomplete' => 'additional-name'])
            . '</div><div class="ssf-row">'
            . self::field('last_name', 'Last name', 'text', ['required' => true, 'autocomplete' => 'family-name'])
            . '<p class="ssf-f"><label for="ssf-suffix">Suffix</label><select id="ssf-suffix" name="suffix">' . $sfx . '</select></p>'
            . '</div><div class="ssf-row">'
            . self::field('birthdate', 'Birthday', 'date', ['required' => true, 'max' => $max, 'hint' => 'Senior citizens are 60 years old and above.'])
            . '<p class="ssf-f"><label for="ssf-sex">Sex</label><select id="ssf-sex" name="sex"><option value="">Prefer not to say</option><option value="F">Female</option><option value="M">Male</option></select></p>'
            . '</div><div class="ssf-row">'
            . self::field('barangay', 'Barangay', 'text', ['required' => true, 'list' => 'ssf-brgy', 'autocomplete' => 'address-level3'])
            . self::field('contact_phone', 'Mobile number', 'tel', ['autocomplete' => 'tel', 'placeholder' => '09XX XXX XXXX', 'hint' => 'So the office can reach you.'])
            . '</div>'
            . self::field('contact_email', 'Email (optional)', 'email', ['autocomplete' => 'email', 'hint' => 'We email your reference number now, and a link to set up your SweetSen sign-in once your record is ready.'])
            . self::field('osca_id', 'OSCA ID number (if you already have one)', 'text')
            . '<datalist id="ssf-brgy">' . $opts . '</datalist>'
            . self::tail('Send registration request');
        return self::css() . '<div class="ssf-wrap" id="register">' . $form . self::done('senior') . '</div>' . self::js();
    }

    public static function sponsor(): string
    {
        if (($closed = self::gate('sponsor')) !== null) {
            return $closed;
        }
        $types = '<option value="">Choose one</option>';
        foreach (Registrations::ORG_TYPES as $k => $v) {
            $types .= '<option value="' . esc_attr($k) . '">' . esc_html($v) . '</option>';
        }
        $form = '<form class="ssf" data-route="public/sponsor-request" novalidate>'
            . self::demoNote()
            . '<h3>About your organization</h3><div class="ssf-row">'
            . self::field('org_name', 'Organization name', 'text', ['required' => true, 'autocomplete' => 'organization'])
            . '<p class="ssf-f"><label for="ssf-org_type">Kind of organization <span class="req">required</span></label><select id="ssf-org_type" name="org_type" required>' . $types . '</select></p>'
            . '</div><h3>Contact person</h3><div class="ssf-row">'
            . self::field('contact_name', 'Full name', 'text', ['required' => true, 'autocomplete' => 'name'])
            . self::field('contact_email', 'Email', 'email', ['required' => true, 'autocomplete' => 'email', 'hint' => 'We send the sign-in link here once approved.'])
            . '</div>'
            . self::field('contact_phone', 'Phone', 'tel', ['autocomplete' => 'tel'])
            . '<p class="ssf-f"><label for="ssf-message">What would you like to give? (optional)</label>'
            . '<textarea id="ssf-message" name="message" rows="3" maxlength="500" placeholder="For example: 100 grocery packs every month, or a cash gift for birthdays"></textarea></p>'
            . self::tail('Send sponsor request');
        return self::css() . '<div class="ssf-wrap" id="register">' . $form . self::done('sponsor') . '</div>' . self::js();
    }

    public static function contact(): string
    {
        if (($closed = self::gate('inquiry')) !== null) {
            return $closed;
        }
        $topics = '<option value="">Choose one</option>';
        foreach (Registrations::TOPICS as $k => $v) {
            $topics .= '<option value="' . esc_attr($k) . '">' . esc_html($v) . '</option>';
        }
        $form = '<form class="ssf" data-route="public/contact" data-reply="1" novalidate>'
            . self::demoNote()
            . self::field('contact_name', 'Your name', 'text', ['required' => true, 'autocomplete' => 'name'])
            . '<p class="ssf-f"><label for="ssf-topic">What is your message about? <span class="req">required</span></label><select id="ssf-topic" name="topic" required>' . $topics . '</select></p>'
            . '<h3>How can we reply?</h3><p class="ssf-hint" id="ssf-reply-hint">Give an email, a mobile number, or both.</p><div class="ssf-row">'
            . self::field('contact_email', 'Email', 'email', ['autocomplete' => 'email'])
            . self::field('contact_phone', 'Mobile number', 'tel', ['autocomplete' => 'tel', 'placeholder' => '09XX XXX XXXX'])
            . '</div>'
            . '<p class="ssf-f"><label for="ssf-message">Your message <span class="req">required</span></label>'
            . '<span class="ssf-hint" id="ssf-message-hint">Up to 500 characters.</span>'
            . '<textarea id="ssf-message" name="message" rows="5" maxlength="500" required aria-describedby="ssf-message-hint"></textarea></p>'
            . self::tail('Send message');
        return self::css() . '<div class="ssf-wrap" id="contact-form">' . $form . self::done('inquiry') . '</div>' . self::js();
    }

    /** @param array<string, mixed> $o */
    private static function field(string $name, string $label, string $type, array $o = []): string
    {
        $id = 'ssf-' . $name;
        $attrs = '';
        foreach (['autocomplete', 'placeholder', 'max', 'list'] as $k) {
            if (isset($o[$k])) {
                $attrs .= ' ' . $k . '="' . esc_attr((string) $o[$k]) . '"';
            }
        }
        $hint = isset($o['hint']) ? '<span class="ssf-hint" id="' . $id . '-hint">' . esc_html($o['hint']) . '</span>' : '';
        return '<p class="ssf-f"><label for="' . $id . '">' . esc_html($label) . (!empty($o['required']) ? ' <span class="req">required</span>' : '') . '</label>' . $hint
            . '<input id="' . $id . '" name="' . esc_attr($name) . '" type="' . esc_attr($type) . '"' . $attrs
            . (!empty($o['required']) ? ' required' : '') . ($hint ? ' aria-describedby="' . $id . '-hint"' : '') . '></p>';
    }

    private static function demoNote(): string
    {
        return '<p class="ssf-demo" role="note"><strong>Demo system.</strong> This website is a demonstration. Please do not enter real personal information. Use made-up names and numbers.</p>';
    }

    private static function tail(string $button): string
    {
        return '<p class="ssf-hp" aria-hidden="true"><label>Leave this empty<input name="website" tabindex="-1" autocomplete="off"></label></p>'
            . '<p class="ssf-check"><label><input type="checkbox" name="demo_ack" value="1" required> I understand this is a demo and I am not entering real personal information.</label></p>'
            . '<p class="ssf-err" role="alert"></p>'
            . '<p><button type="submit" class="wp-element-button ssf-submit">' . esc_html($button) . '</button></p></form>';
    }

    private static function done(string $kind): string
    {
        $steps = match ($kind) {
            'senior' => '<li>The office reviews your request. If you gave an email, check it for your reference number (look in Spam too).</li><li>Visit the senior citizens&#039; office (OSCA) with an ID so your record can be checked.</li><li>You receive your QR code and your SweetSen sign-in.</li>',
            'sponsor' => '<li>The LGU reviews your request. Check your email for your reference number (look in Spam too).</li><li>Once approved, the contact person gets an email with a link to set a password.</li><li>Sign in to see your sponsor dashboard and make your first pledge.</li>',
            default => '<li>Our team reads your message.</li><li>We reply by email or text, using the details you gave.</li>',
        };
        $what = $kind === 'inquiry' ? 'message' : 'request';
        return '<div class="ssf-done" hidden tabindex="-1"><p class="ssf-done-h">Thank you. Your ' . $what . ' was sent.</p>'
            . '<p>Your reference number is <strong class="ssf-ref"></strong>. Keep it in case you need to ask about your ' . $what . '.</p>'
            . '<p><strong>What happens next</strong></p><ol>' . $steps . '</ol></div>';
    }

    private static function css(): string
    {
        static $done = false;
        if ($done) {
            return '';
        }
        $done = true;
        return '<style>'
            . '.ssf-wrap{background:#fff;border:1px solid #C5CCD5;border-top:8px solid #F2B705;border-radius:14px;padding:clamp(1.25rem,3vw,2rem)}'
            . '.ssf h3{margin:1.75rem 0 .5rem;font-size:1.3rem}'
            . '.ssf fieldset{border:0;margin:0 0 .5rem;padding:0;display:flex;flex-wrap:wrap;gap:.75rem}'
            . '.ssf legend{font-weight:700;margin-bottom:.5rem;padding:0;font-size:1.15rem}'
            . '.ssf-opt{display:flex;align-items:center;gap:.6rem;border:2px solid #C5CCD5;border-radius:8px;padding:.7rem 1rem;min-height:3.25rem;cursor:pointer}'
            . '.ssf-opt:has(input:checked){border-color:#22272E;background:#FFF4CC;font-weight:700}'
            . '.ssf input[type=radio],.ssf input[type=checkbox]{width:1.4rem;height:1.4rem;accent-color:#3A424D;flex:none;margin:0}'
            . '.ssf-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(15rem,1fr));gap:0 1.25rem}'
            . '.ssf-f{margin:.75rem 0}'
            . '.ssf-f label{display:block;font-weight:700;margin-bottom:.3rem}'
            . '.ssf .req{font-weight:400;font-size:.9rem;color:#555E69}'
            . '.ssf-hint{display:block;color:#555E69;font-size:.95rem;margin:-.1rem 0 .35rem}'
            . '.ssf input:not([type=radio]):not([type=checkbox]),.ssf select,.ssf textarea{width:100%;box-sizing:border-box;font:inherit;font-size:1.15rem;padding:.7rem .85rem;min-height:3.25rem;border:2px solid #555E69;border-radius:6px;background:#fff;color:#22272E}'
            . '.ssf input:focus,.ssf select:focus,.ssf textarea:focus{outline:4px solid #22272E;outline-offset:2px;box-shadow:0 0 0 8px #F2B705}'
            . '.ssf [aria-invalid=true]{border-color:#A4231B!important;border-width:3px!important}'
            . '.ssf-closed{background:#fff;border:1px solid #C5CCD5;border-top:6px solid #F2B705;border-radius:12px;padding:1.5rem 1.5rem 1rem;font-size:1.1rem}'
            . '.ssf-closed-h{font-size:1.35rem;font-weight:700;margin:0 0 .5rem}'
            . '.ssf-demo{background:repeating-linear-gradient(135deg,#FFF4CC 0 12px,#FFEDB0 12px 24px);border-radius:8px;padding:.85rem 1rem;margin:0 0 1.25rem}'
            . '.ssf-check label{display:flex;gap:.7rem;align-items:flex-start;font-weight:700}'
            . '.ssf-check input{margin-top:.2rem!important}'
            . '.ssf-hp{position:absolute!important;left:-9999px!important;width:1px;height:1px;overflow:hidden}'
            . '.ssf-err{color:#A4231B;font-weight:700;min-height:1.2em}'
            . '.ssf-submit{box-sizing:border-box;min-height:3.25rem;padding:.75em 1.5em!important;border:2px solid #22272E!important;font-size:1.1rem!important;font-weight:700;line-height:1.2;cursor:pointer}'
            . '@media (max-width:600px){.ssf-submit{width:100%}}'
            . '.ssf-submit[disabled]{opacity:.6;cursor:progress}'
            . '.ssf-done-h{font-size:1.5rem;font-weight:700;margin-top:0}'
            . '.ssf-done ol li{margin:.4rem 0}'
            . '[hidden]{display:none!important}'
            . '</style>';
    }

    private static function js(): string
    {
        static $done = false;
        if ($done) {
            return '';
        }
        $done = true;
        $rest = esc_url_raw(rest_url('sweetsen/v1/'));
        return '<script>(function(){var REST=' . wp_json_encode($rest) . ';'
            . <<<'JS'
document.querySelectorAll('form.ssf').forEach(function (f) {
  var t0 = Date.now(), wrap = f.parentNode, err = f.querySelector('.ssf-err'), btn = f.querySelector('.ssf-submit'), label = btn.textContent;
  var fam = wrap.querySelector('.ssf-family');
  f.querySelectorAll('input[name=filed_by]').forEach(function (r) { r.addEventListener('change', function () { if (fam) fam.hidden = f.filed_by.value !== 'family'; }); });
  f.addEventListener('submit', function (e) {
    e.preventDefault(); err.textContent = '';
    f.querySelectorAll('[aria-invalid]').forEach(function (x) { x.removeAttribute('aria-invalid'); });
    var bad = null;
    f.querySelectorAll('[required]').forEach(function (x) {
      var empty = x.type === 'checkbox' ? !x.checked : !x.value.trim();
      if (!bad && (empty || (x.type === 'email' && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(x.value)))) bad = x;
    });
    if (fam && !fam.hidden && !f.filer_name.value.trim()) bad = bad || f.filer_name;
    var noReply = !bad && f.dataset.reply && !f.contact_email.value.trim() && !f.contact_phone.value.trim();
    if (noReply) {
      f.contact_email.setAttribute('aria-invalid', 'true'); f.contact_email.focus();
      err.textContent = 'Please give an email or a mobile number so we can reply.'; return;
    }
    if (bad) {
      bad.setAttribute('aria-invalid', 'true'); bad.focus();
      var lab = bad.type === 'checkbox' ? 'Please tick the demo box.' : 'Please fill in: ' + (f.querySelector('label[for="' + bad.id + '"]') || {}).textContent.replace(' required', '') + '.';
      err.textContent = lab; return;
    }
    var data = {}; new FormData(f).forEach(function (v, k) { data[k] = v; });
    data.elapsed_ms = Date.now() - t0;
    btn.disabled = true; btn.textContent = 'Sending…';
    fetch(REST + f.dataset.route, { method: 'POST', credentials: 'omit', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) })
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { if (!r.ok) throw new Error(j.message || 'Something went wrong. Please try again.'); return j; }); },
            function () { throw new Error('No internet connection. Please try again.'); })
      .then(function (j) {
        var d = wrap.querySelector('.ssf-done'); d.querySelector('.ssf-ref').textContent = j.ref_no; f.hidden = true; d.hidden = false; d.focus();
        d.scrollIntoView({ behavior: 'smooth', block: 'start' });
      })
      .catch(function (x) { btn.disabled = false; btn.textContent = label; err.textContent = x.message; });
  });
});
})();</script>
JS;
    }
}
