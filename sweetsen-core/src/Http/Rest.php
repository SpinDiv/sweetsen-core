<?php

declare(strict_types=1);

namespace SweetSen\Http;

use SweetSen\Domain\Applications;
use SweetSen\Domain\Beneficiaries;
use SweetSen\Domain\Importer;
use SweetSen\Domain\Programs;
use SweetSen\Domain\Registrations;
use SweetSen\Domain\Release;
use SweetSen\Domain\SelfService;
use SweetSen\Domain\SponsorView;
use SweetSen\Domain\Sponsors;
use SweetSen\Support\DomainError;
use SweetSen\Tenancy\Actor;

/**
 * REST API under /wp-json/sweetsen/v1. Cookie auth + the wp_rest nonce (sent as X-WP-Nonce).
 * Routes only require a logged-in user; every authorization decision is made inside the services.
 */
final class Rest
{
    public const NS = 'sweetsen/v1';

    private const STATUS = [
        'not_logged_in' => 401, 'forbidden' => 403, 'no_membership' => 403, 'tenant_required' => 403,
        'not_found' => 404, 'rate_limited' => 429,
        'already_released' => 409, 'not_releasable' => 409, 'not_reversible' => 409, 'idempotency_conflict' => 409,
        'separation_of_duties' => 409, 'claim_limit_reached' => 409, 'outside_validity' => 409,
        'invalid_transition' => 409, 'beneficiary_not_verified' => 409, 'already_verified' => 409,
        'pledge_not_approved' => 409, 'over_allocated' => 409,
        'already_applied' => 409, 'program_closed' => 409, 'no_record' => 404,
        'no_organization' => 403, 'organization_not_verified' => 403,
        'already_requested' => 409, 'possible_duplicate' => 409, 'email_in_use' => 409, 'not_available' => 503,
        'invalid_program' => 422, 'program_locked' => 409, 'checklist_incomplete' => 422, 'no_email' => 409, 'no_qr' => 409, 'form_closed' => 409, 'daily_limit' => 429,
    ];

    public static function register(): void
    {
        $loggedIn = static fn(): bool => is_user_logged_in();

        register_rest_route(self::NS, '/desk/scan', [
            'methods' => 'POST',
            'permission_callback' => $loggedIn,
            'args' => ['payload' => ['type' => 'string', 'required' => true]],
            'callback' => self::handler(static fn(Actor $a, \WP_REST_Request $r) => Release::lookup($a, (string) $r['payload'])),
        ]);

        register_rest_route(self::NS, '/desk/find', [
            'methods' => 'POST',
            'permission_callback' => $loggedIn,
            'callback' => self::handler(static fn(Actor $a, \WP_REST_Request $r) => ['results' => Release::find(
                $a, (string) ($r['ref_no'] ?? ''), (string) ($r['last_name'] ?? ''), (string) ($r['birthdate'] ?? '')
            )]),
        ]);

        register_rest_route(self::NS, '/desk/release', [
            'methods' => 'POST',
            'permission_callback' => $loggedIn,
            'args' => [
                'entitlement_id' => ['type' => 'string', 'required' => true],
                'idempotency_key' => ['type' => 'string', 'required' => true],
                'method' => ['type' => 'string', 'enum' => ['qr', 'manual'], 'default' => 'qr'],
            ],
            'callback' => self::handler(static fn(Actor $a, \WP_REST_Request $r) => Release::release(
                $a,
                (string) $r['entitlement_id'],
                (string) $r['idempotency_key'],
                (string) $r['method'],
                $r['credential_id'] ? (string) $r['credential_id'] : null,
                (string) ($r['note'] ?? '')
            )),
        ]);

        register_rest_route(self::NS, '/desk/today', [
            'methods' => 'GET',
            'permission_callback' => $loggedIn,
            'callback' => self::handler(static fn(Actor $a) => Release::today($a)),
        ]);

        self::registerConsole($loggedIn);
        self::registerSenior($loggedIn);
        self::registerPublic();
        register_rest_route(self::NS, '/sponsor/pledges', [
            'methods' => 'POST', 'permission_callback' => $loggedIn,
            'callback' => self::handler(static function (Actor $a, \WP_REST_Request $r) {
                SponsorView::pledge($a, (array) $r->get_json_params());
                return ['message' => 'Thank you. Your pledge was sent to the LGU for review.'];
            }),
        ]);
    }

    /** Website forms. No login: each request only lands in the LGU review queue. */
    private static function registerPublic(): void
    {
        foreach (['senior-request' => 'submitSenior', 'sponsor-request' => 'submitSponsor', 'contact' => 'submitInquiry'] as $route => $method) {
            register_rest_route(self::NS, '/public/' . $route, [
                'methods' => 'POST', 'permission_callback' => '__return_true',
                'callback' => static function (\WP_REST_Request $r) use ($method) {
                    try {
                        $ip = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . wp_salt('auth'));
                        $ref = Registrations::$method(Registrations::publicTenant(), (array) $r->get_json_params(), $ip);
                        $res = rest_ensure_response(['ref_no' => $ref, 'message' => 'Thank you. Your request was sent.']);
                        $res->header('Cache-Control', 'no-store');
                        return $res;
                    } catch (DomainError $e) {
                        return new \WP_Error($e->reason, $e->getMessage(), ['status' => self::STATUS[$e->reason] ?? 422]);
                    } catch (\Throwable $e) {
                        error_log('[sweetsen] public ' . get_class($e) . ': ' . $e->getMessage());
                        return new \WP_Error('server_error', 'Something went wrong on our side. Please try again later.', ['status' => 500]);
                    }
                },
            ]);
        }
    }

    /** Senior self-service. The senior record is always the one linked to the login, never taken from input. */
    private static function registerSenior(callable $loggedIn): void
    {
        $id = '(?P<id>[0-9a-f]{32})';
        register_rest_route(self::NS, '/me/apply', [
            'methods' => 'POST', 'permission_callback' => $loggedIn,
            'args' => ['program_id' => ['type' => 'string', 'required' => true]],
            'callback' => self::handler(static function (Actor $a, \WP_REST_Request $r) {
                SelfService::apply($a, (string) $r['program_id']);
                return ['message' => 'Your application was sent. The office will review it.'];
            }),
        ]);
        register_rest_route(self::NS, "/me/applications/{$id}/withdraw", [
            'methods' => 'POST', 'permission_callback' => $loggedIn,
            'callback' => self::handler(static function (Actor $a, \WP_REST_Request $r) {
                SelfService::withdraw($a, (string) $r['id']);
                return ['message' => 'Your application was withdrawn.'];
            }),
        ]);
        register_rest_route(self::NS, "/me/applications/{$id}/resubmit", [
            'methods' => 'POST', 'permission_callback' => $loggedIn,
            'callback' => self::handler(static function (Actor $a, \WP_REST_Request $r) {
                SelfService::resubmit($a, (string) $r['id']);
                return ['message' => 'Thank you. The office will look at your application again.'];
            }),
        ]);
    }

    /** LGU console actions. Each one is a single service call; the page reloads to show the result. */
    private static function registerConsole(callable $loggedIn): void
    {
        $id = '(?P<id>[0-9a-f]{32})';
        $post = static fn(string $route, callable $fn) => register_rest_route(self::NS, $route, [
            'methods' => 'POST', 'permission_callback' => $loggedIn, 'callback' => self::handler($fn),
        ]);

        $post("/console/seniors/{$id}/verify", static function (Actor $a, \WP_REST_Request $r) {
            Beneficiaries::verify($a, (string) $r['id'], (array) $r->get_json_params());
            return ['message' => 'Senior verified.'];
        });
        $post("/console/seniors/{$id}/qr", static function (Actor $a, \WP_REST_Request $r) {
            $qr = Beneficiaries::issueQr($a, (string) $r['id'], (string) ($r['reason'] ?? 'replaced'));
            return ['message' => 'QR code issued. Any earlier code for this senior no longer works.', 'qr_payload' => $qr['qr_payload']];
        });
        $post("/console/seniors/{$id}/qr/email", static function (Actor $a, \WP_REST_Request $r) {
            $masked = Beneficiaries::emailQr($a, (string) $r['id'], (string) ($r['image'] ?? ''));
            return ['message' => 'New QR code emailed to ' . $masked . '.'];
        });
        register_rest_route(self::NS, "/console/seniors/{$id}/qr/email", [
            'methods' => 'GET', 'permission_callback' => $loggedIn,
            'callback' => self::handler(static function (Actor $a, \WP_REST_Request $r) {
                $to = Beneficiaries::emailOnFile($a, (string) $r['id']);
                return $to === null ? ['email' => null] : ['email' => $to['masked'], 'source' => $to['source'], 'demo' => $to['demo']];
            }),
        ]);
        register_rest_route(self::NS, "/console/seniors/{$id}/review", [
            'methods' => 'GET', 'permission_callback' => $loggedIn,
            'callback' => self::handler(static fn(Actor $a, \WP_REST_Request $r) => \SweetSen\Domain\Listings::seniorReview($a, (string) $r['id'])),
        ]);
        register_rest_route(self::NS, "/console/seniors/{$id}/qr", [
            'methods' => 'GET', 'permission_callback' => $loggedIn,
            'callback' => self::handler(static fn(Actor $a, \WP_REST_Request $r) => ['qr_payload' => Beneficiaries::qrPayload($a, (string) $r['id'])]),
        ]);
        $post("/console/applications/{$id}/transition", static function (Actor $a, \WP_REST_Request $r) {
            $to = (string) $r['to'];
            Applications::transition($a, (string) $r['id'], $to, $r['note'] ? (string) $r['note'] : null);
            return ['message' => self::DONE[$to] ?? 'Application updated.'];
        });
        $post('/console/form-limits', static function (Actor $a, \WP_REST_Request $r) {
            \SweetSen\Domain\FormLimits::save($a, (array) $r->get_json_params());
            return ['message' => 'Form settings saved. The website shows the change straight away.'];
        });
        $post('/console/programs', static function (Actor $a, \WP_REST_Request $r) {
            Programs::draft($a, (array) $r->get_json_params());
            return ['message' => 'Program saved as a draft. Seniors will see it once you publish it.'];
        });
        $post("/console/programs/{$id}", static function (Actor $a, \WP_REST_Request $r) {
            Programs::update($a, (string) $r['id'], (array) $r->get_json_params());
            return ['message' => 'Program updated.'];
        });
        $post("/console/programs/{$id}/publish", static function (Actor $a, \WP_REST_Request $r) {
            Programs::publish($a, (string) $r['id']);
            return ['message' => 'Program published. Seniors can apply from its start date, and the website lists it.'];
        });
        $post("/console/programs/{$id}/close", static function (Actor $a, \WP_REST_Request $r) {
            Programs::close($a, (string) $r['id']);
            return ['message' => 'Program closed. No new applications; approved benefits can still be released.'];
        });
        $post("/console/pledges/{$id}/review", static function (Actor $a, \WP_REST_Request $r) {
            $ok = (bool) $r['approve'];
            Sponsors::reviewPledge($a, (string) $r['id'], $ok, (string) ($r['note'] ?? ''));
            return ['message' => $ok ? 'Pledge approved.' : 'Pledge rejected.'];
        });
        $post("/console/pledges/{$id}/delivery", static function (Actor $a, \WP_REST_Request $r) {
            Sponsors::deliver($a, (string) $r['id'], $r['quantity'] !== null && $r['quantity'] !== '' ? (int) $r['quantity'] : null,
                $r['amount'] !== null && $r['amount'] !== '' ? (string) $r['amount'] : null);
            return ['message' => 'Delivery recorded and assigned to the program.'];
        });
        $post("/console/releases/{$id}/reverse", static function (Actor $a, \WP_REST_Request $r) {
            Release::reverse($a, (string) $r['id'], (string) ($r['reason'] ?? ''));
            return ['message' => 'Release reversed. The benefit can be released again.'];
        });
        $post("/console/requests/{$id}/approve", static function (Actor $a, \WP_REST_Request $r) {
            $kind = \SweetSen\Support\Db::value(\SweetSen\Support\Db::prepare(
                'SELECT kind FROM ' . \SweetSen\Support\Db::t('registration_requests') . ' WHERE id = UNHEX(%s) AND tenant_id = UNHEX(%s)', (string) $r['id'], $a->tenantId
            ));
            if ($kind === 'sponsor') {
                $res = Registrations::approveSponsor($a, (string) $r['id']);
                return ['message' => "Sponsor account created (username {$res['login']}). A link to set a password was emailed to the contact person."];
            }
            if ($kind === 'senior') {
                $res = Registrations::approveSenior($a, (string) $r['id']);
                $acct = match ($res['account']) {
                    'created' => " Sign-in created (username {$res['login']}) and a welcome email with a set-password link was sent.",
                    'email_in_use' => ' No sign-in was created: another account already uses their email.',
                    default => ' No sign-in was created: they gave no email.',
                };
                return ['message' => "Senior record {$res['ref_no']} created as \"to verify\"." . $acct . ' Verify it after checking their ID.'];
            }
            throw new DomainError('not_found', 'Request not found.');
        });
        $post("/console/requests/{$id}/answer", static function (Actor $a, \WP_REST_Request $r) {
            Registrations::answer($a, (string) $r['id'], (string) ($r['note'] ?? ''));
            return ['message' => 'Message marked as answered.'];
        });
        $post("/console/requests/{$id}/reject", static function (Actor $a, \WP_REST_Request $r) {
            Registrations::reject($a, (string) $r['id'], (string) ($r['note'] ?? ''));
            return ['message' => 'Request rejected.'];
        });
        $post('/console/import', static function (Actor $a, \WP_REST_Request $r) {
            $file = $r->get_file_params()['file'] ?? null;
            if (!$file || ($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
                throw new DomainError('no_file', 'Choose a CSV file to upload.');
            }
            if ($file['size'] > 2 * 1024 * 1024 || strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION)) !== 'csv') {
                throw new DomainError('bad_file', 'Upload a .csv file under 2 MB.');
            }
            $res = Importer::importCsv($a, (string) $file['tmp_name'], sanitize_file_name((string) $file['name']));
            return ['message' => "{$res['created']} seniors added, {$res['flagged']} flagged for review, {$res['errors']} rows with errors."];
        });
    }

    private const DONE = [
        'under_review' => 'Review started.',
        'approved' => 'Application approved. The benefit is ready for release.',
        'rejected' => 'Application rejected.',
        'info_needed' => 'Senior asked for more information.',
        'withdrawn' => 'Application withdrawn.',
    ];

    /**
     * Wrap a service call: resolve the actor, map DomainError to a JSON error with a stable code,
     * and never leak internals on unexpected failures.
     *
     * @param callable(Actor, \WP_REST_Request): mixed $fn
     */
    public static function handler(callable $fn): callable
    {
        return static function (\WP_REST_Request $request) use ($fn) {
            try {
                $result = $fn(Actor::current(), $request);
                $response = rest_ensure_response($result);
                $response->header('Cache-Control', 'no-store');
                return $response;
            } catch (DomainError $e) {
                return new \WP_Error($e->reason, $e->getMessage(), ['status' => self::STATUS[$e->reason] ?? 422] + $e->context);
            } catch (\Throwable $e) {
                error_log('[sweetsen] ' . get_class($e) . ': ' . $e->getMessage());
                return new \WP_Error('server_error', 'Something went wrong on the server. Try again; if it repeats, use the paper log.', ['status' => 500]);
            }
        };
    }
}
