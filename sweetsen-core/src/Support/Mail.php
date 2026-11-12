<?php

declare(strict_types=1);

namespace SweetSen\Support;

/**
 * One look for every SweetSen email: cool grey ground, white card with the golden top bar, Atkinson Hyperlegible
 * (falls back to Arial where mail apps block web fonts), large text, one yellow button. Inline styles only, because
 * most mail apps ignore <style>. Sent with wp_mail from no-reply@<site host>.
 */
final class Mail
{
    private const FONT = "'Atkinson Hyperlegible',Arial,Helvetica,sans-serif";

    /** Mail apps cannot deliver to these (demo and reserved domains). */
    public static function isDemo(string $email): bool
    {
        return (bool) preg_match('/\.(example|test|invalid|localhost)$/i', (string) substr(strrchr($email, '@') ?: '', 1));
    }

    public static function fromAddress(): string
    {
        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        return 'no-reply@' . preg_replace('/^www\./', '', $host);
    }

    /**
     * The sampaguita mark as a PNG (mail apps do not show SVG). Rendered from Portal\Brand::SVG and stored in uploads/sweetsen/.
     */
    public static function markUrl(): string
    {
        return wp_upload_dir(null, false)['baseurl'] . '/sweetsen/sweetsen-mark-96.png';
    }

    public static function p(string $html, bool $small = false): string
    {
        return '<p style="margin:0 0 16px;font-size:' . ($small ? '15px' : '17px') . ';line-height:1.55;color:' . ($small ? '#555E69' : '#22272E') . '">' . $html . '</p>';
    }

    /** A grey box with a label and a large value (reference numbers, usernames). */
    public static function fact(string $label, string $value): string
    {
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 16px"><tr><td style="background:#ECEFF3;border-radius:8px;padding:14px 16px">'
            . '<div style="font-size:14px;color:#555E69;margin:0 0 4px">' . esc_html($label) . '</div>'
            . '<div style="font-size:20px;font-weight:700;color:#22272E;letter-spacing:.02em">' . esc_html($value) . '</div></td></tr></table>';
    }

    /** Numbered steps with yellow number chips. @param list<string> $items HTML */
    public static function steps(array $items): string
    {
        $rows = '';
        foreach (array_values($items) as $i => $item) {
            $rows .= '<tr><td valign="top" style="padding:0 12px 12px 0;width:28px"><div style="width:28px;height:28px;line-height:28px;text-align:center;border-radius:14px;background:#F2B705;color:#22272E;font-weight:700;font-size:15px">' . ($i + 1) . '</div></td>'
                . '<td valign="top" style="padding:3px 0 12px;font-size:17px;line-height:1.5;color:#22272E">' . $item . '</td></tr>';
        }
        return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 8px">' . $rows . '</table>';
    }

    public static function button(string $label, string $url): string
    {
        return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 20px"><tr><td style="background:#F2B705;border:2px solid #22272E;border-radius:8px">'
            . '<a href="' . esc_url($url) . '" style="display:inline-block;padding:14px 28px;font-family:' . self::FONT . ';font-size:18px;font-weight:700;color:#22272E;text-decoration:none">' . esc_html($label) . '</a></td></tr></table>';
    }

    public static function render(string $heading, string $body, string $preheader = ''): string
    {
        $home = esc_url(home_url('/'));
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light">'
            . '<link href="https://fonts.googleapis.com/css2?family=Atkinson+Hyperlegible:wght@400;700&display=swap" rel="stylesheet"><title>' . esc_html($heading) . '</title></head>'
            . '<body style="margin:0;padding:0;background:#ECEFF3;font-family:' . self::FONT . '">'
            . ($preheader !== '' ? '<div style="display:none;max-height:0;overflow:hidden;opacity:0">' . esc_html($preheader) . '</div>' : '')
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#ECEFF3"><tr><td align="center" style="padding:28px 16px">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;font-family:' . self::FONT . '">'
            . '<tr><td style="padding:0 4px 16px"><a href="' . $home . '" style="text-decoration:none;color:#22272E;font-size:24px;font-weight:700">'
            . '<img src="' . esc_url(self::markUrl()) . '" width="34" height="34" alt="" style="display:inline-block;width:34px;height:34px;border:0;vertical-align:middle;margin:0 10px 0 0">'
            . '<span style="vertical-align:middle">SweetSen</span></a></td></tr>'
            . '<tr><td style="background:#ffffff;border-top:8px solid #F2B705;border-radius:14px;padding:32px 28px">'
            . '<h1 style="margin:0 0 16px;font-size:26px;line-height:1.25;font-weight:700;color:#22272E">' . esc_html($heading) . '</h1>' . $body . '</td></tr>'
            . '<tr><td style="padding:20px 4px 0;font-size:13px;line-height:1.5;color:#555E69">You received this because someone used this email address on SweetSen. '
            . 'This is an automatic message: replies are not read.<br>SweetSen demo with synthetic records. Not an official government website or message.</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    /**
     * Send one branded email. $text is the plain-text version for mail apps that do not show HTML.
     * $setup can add attachments or embedded images to the PHPMailer object.
     */
    public static function send(string $to, string $subject, string $html, string $text, ?callable $setup = null): bool
    {
        $hook = static function ($mailer) use ($text, $setup): void {
            $mailer->AltBody = $text;
            if ($setup !== null) {
                $setup($mailer);
            }
        };
        add_action('phpmailer_init', $hook);
        try {
            return wp_mail($to, $subject, $html, ['Content-Type: text/html; charset=UTF-8', 'From: SweetSen <' . self::fromAddress() . '>']);
        } finally {
            remove_action('phpmailer_init', $hook);
        }
    }
}
