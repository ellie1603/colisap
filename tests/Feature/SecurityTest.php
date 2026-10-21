<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\Auth\Login;
use App\Filament\Resources\AuditLogResource;
use App\Filament\Resources\MemberResource;
use App\Filament\Resources\MemberResource\Pages\ListMembers;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\AuditLog;
use App\Models\Member;
use App\Models\User;
use App\Services\Access\Permissions;
use App\Services\Masterlist\WorkbookParser;
use App\Services\Reports\SpreadsheetWriter;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\Concerns\ActsAsRole;
use Tests\TestCase;
use ZipArchive;

/**
 * Regression tests for the security audit (security-audit-checklist.md): each attack that is blocked stays blocked.
 */
class SecurityTest extends TestCase
{
    use ActsAsRole, LazilyRefreshDatabase;

    protected function tearDown(): void
    {
        RateLimiter::clear('colisap-login:account:officer@bmpc.coop|127.0.0.1');
        RateLimiter::clear('colisap-login:ip:127.0.0.1');

        parent::tearDown();
    }

    public function test_pages_send_a_content_security_policy_and_permissions_policy(): void
    {
        $response = $this->get('/admin/login')->assertOk();

        $policy = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("frame-ancestors 'self'", $policy, 'Clickjacking: other sites cannot frame COLISAP.');
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringContainsString("form-action 'self'", $policy);
        $this->assertStringContainsString('camera=()', (string) $response->headers->get('Permissions-Policy'));
    }

    public function test_other_websites_cannot_call_the_api_from_a_browser(): void
    {
        $this->withHeaders(['Origin' => 'https://evil.example', 'Access-Control-Request-Method' => 'GET'])
            ->options('/api/monitoring/v1/summary')
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_a_password_known_from_data_breaches_is_refused(): void
    {
        $hash = strtoupper(sha1('Password123'));
        $this->pwnedPasswordsResponse = substr($hash, 5).":52000\r\n0000000000000000000000000000000000A:1";
        $this->actingAsDeveloper();

        Livewire::test(ListUsers::class)
            ->callAction('create', data: [
                'name' => 'New Officer', 'email' => 'new@bmpc.coop', 'password' => 'Password123',
                'roles' => [Role::findByName(Permissions::CRS, 'web')->id], 'is_active' => true,
            ])
            ->assertHasActionErrors(['password']);

        $this->assertNull(User::where('email', 'new@bmpc.coop')->first());
    }

    public function test_failed_sign_ins_and_lockouts_are_logged_without_the_password(): void
    {
        $officer = User::factory()->create(['email' => 'officer@bmpc.coop', 'password' => 'right-pass-1', 'is_active' => true])->assignRole(Permissions::CRS);

        foreach (range(1, Login::MAX_ATTEMPTS_PER_ACCOUNT) as $attempt) {
            Livewire::test(Login::class)->fillForm(['email' => 'officer@bmpc.coop', 'password' => 'guess-'.$attempt])->call('authenticate');
        }

        $entries = AuditLog::where('module', 'Sign-in')->orderBy('id')->get();
        $this->assertSame(Login::MAX_ATTEMPTS_PER_ACCOUNT - 1, $entries->where('action', 'login_failed')->count());
        $this->assertSame(1, $entries->where('action', 'login_locked')->count());
        $this->assertSame($officer->id, $entries->last()->auditable_id);
        $this->assertStringNotContainsString('guess-', json_encode($entries));
    }

    public function test_the_administrator_is_alerted_on_the_dashboard_after_a_lockout(): void
    {
        User::factory()->create(['email' => 'officer@bmpc.coop', 'password' => 'right-pass-1', 'is_active' => true])->assignRole(Permissions::CRS);

        foreach (range(1, Login::MAX_ATTEMPTS_PER_ACCOUNT) as $attempt) {
            Livewire::test(Login::class)->fillForm(['email' => 'officer@bmpc.coop', 'password' => 'guess-'.$attempt])->call('authenticate');
        }

        $this->actingAsRole(Permissions::ADMIN);

        $this->get('/admin')->assertOk()->assertSee('Repeated failed sign-ins');
        $this->get(AuditLogResource::getUrl('index', ['tableFilters' => ['action' => ['value' => 'login_locked']]]))
            ->assertOk()
            ->assertSee('login_locked');
    }

    public function test_a_password_change_is_logged_without_the_password(): void
    {
        $officer = $this->actingAsRole(Permissions::CRS);

        Livewire::test(EditProfile::class)
            ->fillForm(['password' => 'brand-new-77', 'passwordConfirmation' => 'brand-new-77', 'currentPassword' => 'password'])
            ->call('save')
            ->assertHasNoFormErrors();

        $entry = AuditLog::where('action', 'password_changed')->where('auditable_id', $officer->id)->sole();
        $this->assertSame('Changed own password', $entry->reason);
        $this->assertStringNotContainsString($officer->fresh()->password, json_encode(AuditLog::query()->toBase()->get()));
    }

    public function test_csv_cells_that_excel_would_run_as_formulas_are_neutralised(): void
    {
        $this->assertSame("'=HYPERLINK(\"http://evil\")", SpreadsheetWriter::safeCsvValue('=HYPERLINK("http://evil")'));
        $this->assertSame("'+1+1", SpreadsheetWriter::safeCsvValue('+1+1'));
        $this->assertSame("'@SUM(A1)", SpreadsheetWriter::safeCsvValue('@SUM(A1)'));
        $this->assertSame(-250.5, SpreadsheetWriter::safeCsvValue(-250.5), 'Real numbers stay numbers.');
        $this->assertSame('DELA CRUZ, JUAN', SpreadsheetWriter::safeCsvValue('DELA CRUZ, JUAN'));

        Member::factory()->create(['account_name' => '=HYPERLINK("http://evil","Click")']);
        $this->actingAsRole(Permissions::ADMIN);

        $component = Livewire::test(ListMembers::class)->loadTable()
            ->callAction('export', ['layout' => 'organized', 'format' => 'csv']);
        $csv = base64_decode($component->effects['download']['content']);

        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString(',"=HYPERLINK', $csv);
    }

    public function test_script_typed_into_a_name_is_shown_as_text(): void
    {
        $member = Member::factory()->create(['account_name' => '<script>alert(1)</script>', 'first_name' => '"><img src=x onerror=alert(1)>']);
        $this->actingAsRole(Permissions::ADMIN);

        foreach ([MemberResource::getUrl('index'), MemberResource::getUrl('view', ['record' => $member])] as $url) {
            $this->get($url)->assertOk()
                ->assertDontSee('<script>alert(1)</script>', escape: false)
                ->assertDontSee('<img src=x onerror=alert(1)>', escape: false)
                ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', escape: false);
        }
    }

    /**
     * Uploads are checked by their content, not their name: Livewire reads the first 64 KB of the real file
     * (in tests it uses the declared type instead, so the rule is checked here on real files).
     */
    public function test_a_php_file_renamed_to_jpg_is_refused_as_a_withdrawal_letter(): void
    {
        $rule = ['document' => 'mimetypes:application/pdf,image/jpeg,image/png'];

        // Harmless PHP: a real web-shell sample is (rightly) quarantined by antivirus before the test can use it.
        $disguised = tempnam(sys_get_temp_dir(), 'shell');
        file_put_contents($disguised, "<?php\necho 'not a photo';\n");

        $photo = tempnam(sys_get_temp_dir(), 'photo');
        imagepng(imagecreatetruecolor(4, 4), $photo);

        $this->assertTrue(Validator::make(['document' => new UploadedFile($disguised, 'letter.jpg', 'image/jpeg', null, true)], $rule)->fails());
        $this->assertFalse(Validator::make(['document' => new UploadedFile($photo, 'letter.png', 'image/png', null, true)], $rule)->fails());
        $this->assertStringContainsString("'application/pdf', 'image/jpeg', 'image/png'", (string) file_get_contents(app_path('Filament/Resources/MemberResource/Pages/ViewMember.php')), 'The withdrawal upload accepts only these types.');
    }

    public function test_a_zip_bomb_disguised_as_a_workbook_is_refused_before_it_is_read(): void
    {
        $bomb = tempnam(sys_get_temp_dir(), 'bomb').'.xlsx';
        $zip = new ZipArchive;
        $zip->open($bomb, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zeros = str_repeat("\0", 10 * 1048576);
        foreach (range(1, 6) as $part) {
            $zip->addFromString("xl/worksheets/sheet{$part}.xml", $zeros);
        }
        $zip->close();
        unset($zeros);

        $this->assertLessThan(1048576, filesize($bomb), 'The file itself is small…');

        try {
            WorkbookParser::guardAgainstZipBomb($bomb);
            $this->fail('A zip bomb was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('not a normal workbook', $exception->getMessage());
        }

        $normal = tempnam(sys_get_temp_dir(), 'book').'.xlsx';
        $book = new ZipArchive;
        $book->open($normal, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $book->addFromString('xl/worksheets/sheet1.xml', str_repeat('<row><c t="s"><v>1</v></c></row>', 2000));
        $book->close();

        WorkbookParser::guardAgainstZipBomb($normal);
        $this->addToAssertionCount(1);
    }

    public function test_the_account_check_finds_default_passwords_and_test_accounts_without_showing_passwords(): void
    {
        $this->travelTo('2026-10-19 09:00:00');
        User::factory()->create(['email' => 'weak@bmpc.coop', 'password' => 'password123', 'is_active' => true]);
        User::factory()->create(['email' => 'demo.officer@bmpc.coop', 'password' => 'Long-unique-Pass-7731', 'is_active' => true]);
        $good = User::factory()->create(['email' => 'maria@bmpc.coop', 'password' => 'Long-unique-Pass-7732', 'is_active' => true]);
        $good->recordActivity('login', 'Sign-in');

        $this->assertSame(1, Artisan::call('colisap:check-accounts'));
        $output = Artisan::output();

        $this->assertMatchesRegularExpression('/weak@bmpc\.coop.*Uses a default or very common password/', $output);
        $this->assertStringContainsString('demo.officer@bmpc.coop', $output);
        $this->assertStringNotContainsString('maria@bmpc.coop', $output);
        $this->assertStringNotContainsString('password123', $output, 'Passwords are never shown.');

        User::whereIn('email', ['weak@bmpc.coop', 'demo.officer@bmpc.coop'])->delete();
        $this->artisan('colisap:check-accounts')->assertSuccessful();
    }

    public function test_every_session_can_be_ended_at_once(): void
    {
        $officer = User::factory()->create(['is_active' => true, 'remember_token' => 'old-token']);
        DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $officer->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'payload' => '', 'last_activity' => time()]);

        $this->artisan('colisap:sign-out-everyone', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, DB::table('sessions')->count());
        $this->assertNotSame('old-token', $officer->fresh()->remember_token);
    }

    public function test_staff_names_are_not_sent_to_an_outside_avatar_service(): void
    {
        $this->actingAsRole(Permissions::ADMIN);

        $this->get('/admin')->assertOk()->assertDontSee('ui-avatars.com', escape: false)->assertSee('data:image/svg+xml;base64', escape: false);
    }
}
