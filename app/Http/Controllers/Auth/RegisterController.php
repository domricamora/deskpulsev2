<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Services\Mail\SalesNotifier;
use App\Services\Sharing\PersonalLinks;
use App\Support\Attribution;
use App\Support\Flash;
use App\Support\Plans;
use App\Support\Platform;
use App\Support\Subscription;
use App\Support\Track;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Self-serve signup: plan chosen on /pricing → account → trial → dashboard.
 *
 * Design constraints carried over verbatim, because each one was a fix:
 *  - The plan rides as a HIDDEN FIELD, not only as ?plan=. The form POSTs to a
 *    bare /register with no query string, so a visitor arriving from a pricing
 *    CTA used to silently land on the default plan whatever they clicked.
 *  - Solo and Individual are single-user plans, so a "company workspace name" is
 *    pure friction there: optional, and derived from the person's name.
 *  - Every field repopulates on error. Losing four fields to one typo is the
 *    single biggest avoidable drop-off in a signup form.
 *
 * @see docs/migration/authentication.md §4
 */
class RegisterController extends Controller
{
    /** Enterprise is excluded: it carries a negotiated fee a super admin sets. */
    private const SELF_SERVE_PLANS = ['solo', 'individual', 'per_seat', 'organization'];

    public function __construct(
        private readonly Platform $platform,
        private readonly Plans $plans,
        private readonly Subscription $subscription,
        private readonly PersonalLinks $personalLinks,
        private readonly SalesNotifier $sales,
    ) {}

    /** GET /register */
    public function show(Request $request)
    {
        return $this->form($this->plan($request), ['company' => '', 'name' => '', 'email' => '']);
    }

    /** POST /register */
    public function store(Request $request)
    {
        $plan = $this->plan($request);
        $soloish = in_array($plan, ['solo', 'individual'], true);

        $company = trim((string) $request->input('company'));
        $name = trim((string) $request->input('name'));
        $email = strtolower(trim((string) $request->input('email')));
        $password = (string) $request->input('password');

        $old = ['company' => $company, 'name' => $name, 'email' => $email];

        // A single-user plan doesn't need a company name — derive one.
        if ($company === '' && $soloish && $name !== '') {
            $company = $name;
        }

        $error = $this->validationError($company, $name, $email, $password, $soloish);

        if ($error !== null) {
            Flash::error($error);

            return $this->form($plan, $old);
        }

        // With payments on, signups start on a free trial with immediate access
        // (a super admin still oversees on the Platform page); otherwise they
        // fall back to the older "pending until approved" gate.
        $status = $this->platform->paymentsEnabled() ? 'approved' : 'pending';

        $org = Organization::create(['name' => $company, 'status' => $status]);

        $user = User::create([
            'org_id'        => $org->id,
            'name'          => $name,
            'email'         => $email,
            'password_hash' => bcrypt($password),
            'role'          => UserRole::ClientAdmin,
        ]);

        $this->personalLinks->ensureFor((int) $org->id);
        $this->subscription->startTrial($org, $plan);
        Attribution::stamp((int) $org->id);

        $this->sales->notify('signup', 'New DeskPulse signup: ' . $company, [
            'Organization' => $company,
            'Plan'         => $plan,
            'Contact'      => $name,
            'Email'        => $email,
            'Status'       => $status,
            'Source'       => session('dp_utm.utm_source', 'direct'),
        ], (int) $org->id);

        Auth::login($user);
        $request->session()->regenerate();

        // The conversion fires on the next page render — a 302 is invisible to
        // a tag.
        Track::queue('sign_up', ['method' => 'email', 'plan' => $plan]);

        return redirect('/app');
    }

    /* ── Parts ───────────────────────────────────────────────────────────── */

    /** The plan may arrive on the link (GET) or on the round-trip (POST). */
    private function plan(Request $request): string
    {
        $requested = (string) $request->input('plan', $request->query('plan', ''));

        return in_array($requested, self::SELF_SERVE_PLANS, true) ? $requested : 'per_seat';
    }

    /**
     * One message at a time, in the legacy order.
     *
     * The "already registered" copy names the remedy instead of only stating the
     * problem; the template renders a Sign in link beside the form.
     */
    private function validationError(
        string $company,
        string $name,
        string $email,
        string $password,
        bool $soloish,
    ): ?string {
        if (! $company || ! $name || ! $email || ! $password) {
            return $soloish
                ? 'Your name, email and password are required.'
                : 'All fields are required.';
        }

        if (strlen($password) < 8) {
            return 'Password must be at least 8 characters.';
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 'Enter a valid email address.';
        }

        if (User::query()->where('email', $email)->exists()) {
            return 'That email is already registered — sign in instead, or use another address.';
        }

        return null;
    }

    /** @param array{company: string, name: string, email: string} $old */
    private function form(string $plan, array $old)
    {
        $prices = $this->plans->basePrices();

        return view('auth.register', [
            'title'      => 'Create your DeskPulse account',
            'plan'       => $plan,
            'soloish'    => in_array($plan, ['solo', 'individual'], true),
            'old'        => $old,
            'prices'     => $prices,
            'trialDays'  => $this->platform->trialDays(),
            'planPrice'  => $this->plans->signupPrice($plan, $prices),
        ]);
    }
}
