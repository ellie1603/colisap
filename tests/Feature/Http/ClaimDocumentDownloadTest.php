<?php

namespace Tests\Feature\Http;

use App\Models\Claim;
use App\Models\ClaimDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ClaimDocumentDownloadTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function documentFor(Claim $claim): ClaimDocument
    {
        Storage::disk('local')->put("claims/{$claim->id}/abc123.pdf", 'pdf-bytes');

        return ClaimDocument::create([
            'claim_id' => $claim->id,
            'document_type' => 'Death Certificate',
            'file_path' => "claims/{$claim->id}/abc123.pdf",
            'original_filename' => 'death-certificate.pdf',
        ]);
    }

    private function staff(): User
    {
        return User::factory()->create(['is_active' => true])->assignRole('crs');
    }

    public function test_downloads_document_with_its_original_filename(): void
    {
        Storage::fake('local');
        $document = $this->documentFor(Claim::factory()->create());

        $this->actingAs($this->staff())
            ->get(route('claim-documents.download', $document))
            ->assertOk()
            ->assertDownload('death-certificate.pdf');
    }

    public function test_returns_404_when_claims_member_was_deleted(): void
    {
        Storage::fake('local');
        $claim = Claim::factory()->create();
        $document = $this->documentFor($claim);
        $claim->member->delete();

        $this->actingAs($this->staff())
            ->get(route('claim-documents.download', $document))
            ->assertNotFound();
    }

    public function test_user_without_a_role_is_forbidden(): void
    {
        Storage::fake('local');
        $document = $this->documentFor(Claim::factory()->create());

        $this->actingAs(User::factory()->create(['is_active' => true]))
            ->get(route('claim-documents.download', $document))
            ->assertForbidden();
    }

    public function test_deleting_document_removes_stored_file(): void
    {
        Storage::fake('local');
        $document = $this->documentFor(Claim::factory()->create());

        $document->delete();

        Storage::disk('local')->assertMissing($document->file_path);
    }
}
