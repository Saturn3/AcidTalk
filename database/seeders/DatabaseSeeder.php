<?php

namespace Database\Seeders;

use App\Models\Channel;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::current();
        $org->update(['name' => config('organization.name'), 'domain' => config('organization.domain')]);

        $admin = User::firstOrCreate(['email' => 'admin@'.$org->domain], [
            'organization_id' => $org->id, 'name' => 'Admin', 'password' => 'password', 'role' => 'admin', 'title' => 'Amministratore', 'email_verified_at' => now(),
        ]);
        $giulia = User::firstOrCreate(['email' => 'giulia@'.$org->domain], [
            'organization_id' => $org->id, 'name' => 'Giulia Verdi', 'password' => 'password', 'role' => 'member', 'title' => 'Product Designer', 'email_verified_at' => now(),
        ]);
        $marco = User::firstOrCreate(['email' => 'marco@'.$org->domain], [
            'organization_id' => $org->id, 'name' => 'Marco Rossi', 'password' => 'password', 'role' => 'member', 'title' => 'Backend Developer', 'email_verified_at' => now(),
        ]);

        $channels = [
            ['generale', 'public', 'Annunci e discussioni di tutto il team'],
            ['random', 'public', 'Chiacchiere, caffè e cose non di lavoro'],
            ['sviluppo', 'public', 'Release, bug, code review'],
            ['direzione', 'private', 'Canale riservato'],
        ];
        $created = [];
        foreach ($channels as [$name, $type, $topic]) {
            $c = Channel::firstOrCreate(['organization_id' => $org->id, 'slug' => $name], ['name' => $name, 'type' => $type, 'topic' => $topic, 'created_by' => $admin->id]);
            $members = $type === 'private' ? [$admin->id] : [$admin->id, $giulia->id, $marco->id];
            $c->members()->syncWithoutDetaching(collect($members)->mapWithKeys(fn ($id) => [$id => ['role' => $id === $admin->id ? 'owner' : 'member']])->all());
            $created[$name] = $c;
        }
        foreach ([$admin, $giulia, $marco] as $u) {
            Channel::aiDirectFor($u);
        }
        Channel::directBetween($admin, $giulia);

        if (Message::count() === 0) {
            $g = $created['generale'];
            $g->messages()->create(['user_id' => null, 'kind' => 'system', 'body' => "Benvenuti in **#generale**, il canale di tutto il team."]);
            $g->messages()->create(['user_id' => $admin->id, 'body' => "Ciao a tutti e benvenuti su **AcidTalk**! 👋\n\nQui dentro potete usare *Markdown*, `codice inline` e blocchi di codice:\n\n```php\nRoute::get('/hello', fn () => 'Ciao mondo');\n```\n\nPer chiedere aiuto all'assistente scrivete **@ai** seguito dalla domanda."]);
            $g->messages()->create(['user_id' => $giulia->id, 'body' => "Grazie! Ho caricato i mockup della nuova home su Figma, li condivido qui a breve 🎨"]);
            $m = $g->messages()->create(['user_id' => $marco->id, 'body' => "Ricordo a tutti la call di allineamento alle 15:00 — il link lo mando qui nel canale con il tasto videochiamata."]);
            $m->replies()->create(['channel_id' => $g->id, 'user_id' => $giulia->id, 'body' => 'Perfetto, ci sono 👍']);
            $m->update(['reply_count' => 1, 'last_reply_at' => now()]);
            $created['sviluppo']->messages()->create(['user_id' => $marco->id, 'body' => "Deploy della **2.0.0** completato in staging ✅\n\n- editing e cancellazione messaggi\n- thread laterale\n- nuova home\n\nSegnalate eventuali regressioni qui."]);
            $created['random']->messages()->create(['user_id' => $giulia->id, 'body' => 'Qualcuno ha provato il nuovo bar sotto l\'ufficio? ☕']);
            foreach ($created as $c) {
                $c->forceFill(['last_message_at' => now()])->save();
            }
        }
    }
}
