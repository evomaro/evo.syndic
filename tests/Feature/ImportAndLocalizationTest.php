<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Residence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ImportAndLocalizationTest extends TestCase
{
    use RefreshDatabase;

    private function user(): array
    {
        $u = User::factory()->create();
        $o = Organization::factory()->create();
        $r = Residence::factory()->for($o)->create();
        $o->users()->attach($u, ['role' => 'owner', 'all_residences' => true]);
        $u->update(['current_organization_id' => $o->id, 'current_residence_id' => $r->id]);

        return [$u, $o, $r];
    }

    public function test_import_file_mime_and_size_are_validated(): void
    {
        [$u] = $this->user();
        $this->actingAs($u)->post(route('imports.upload'), ['type' => 'lots', 'file' => UploadedFile::fake()->create('payload.exe', 10, 'application/octet-stream')])->assertSessionHasErrors('file');
    }

    public function test_import_batch_is_forced_into_active_tenant(): void
    {
        [$u,$o,$r] = $this->user();
        $file = UploadedFile::fake()->createWithContent('lots.csv', "reference,lot_number,type\nA-1,1,apartment\n");
        $this->actingAs($u)->post(route('imports.upload'), ['type' => 'lots', 'file' => $file])->assertRedirect();
        $this->assertDatabaseHas('import_batches', ['organization_id' => $o->id, 'residence_id' => $r->id, 'type' => 'lots']);
    }

    public function test_arabic_preference_sets_rtl_locale_prop(): void
    {
        [$u] = $this->user();
        $u->update(['preferred_language' => 'ar']);
        $this->actingAs($u)->get(route('dashboard'))->assertOk()->assertInertia(fn ($p) => $p->where('locale', 'ar'));
    }

    public function test_mobile_critical_pages_are_available(): void
    {
        [$u] = $this->user();
        foreach (['dashboard', 'structure.index', 'contacts.index', 'activity.index'] as $route) {
            $this->actingAs($u)->get(route($route), ['User-Agent' => 'Mozilla/5.0 (iPhone)'])->assertOk();
        }
    }
}
