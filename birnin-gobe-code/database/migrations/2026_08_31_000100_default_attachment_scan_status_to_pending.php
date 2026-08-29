<?php

use App\Domain\Application\AttachmentScanStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Le défaut de `attachments.scan_status` devient `PENDING` — §15.1.
 *
 * La colonne naissait à `QUARANTINE`, choisi par le squelette initial comme un
 * défaut prudent : à l'époque, aucune analyse n'existait, et fermer valait
 * mieux qu'ouvrir. Le raisonnement était bon, la valeur non — `QUARANTINE` ne
 * dit pas « on ne sait pas », il dit **« une analyse a eu lieu et a trouvé une
 * menace »**. Une ligne insérée sans passer par `StoreApplicationDocument`
 * naissait donc en accusant le fichier de quelqu'un.
 *
 * Le piège était dormant : le cas d'usage écrit toujours l'état
 * explicitement, donc le défaut n'était jamais atteint. Il n'attendait qu'une
 * insertion faite ailleurs — une reprise de données, une fixture, une correction
 * en base un soir de campagne.
 *
 * **`PENDING` est le seul défaut qui ne mente pas.** Il ferme le téléchargement
 * exactement comme `QUARANTINE` — seul `CLEAN` ouvre — et il est *repris* par
 * `attachments:scan`, là où une quarantaine ne l'est jamais : un verdict rendu
 * ne se rejoue pas. Le défaut se répare donc tout seul, au lieu de figer une
 * accusation.
 *
 * **Pourquoi garder un défaut plutôt que l'interdire.** Une colonne `NOT NULL`
 * sans défaut ferait échouer bruyamment toute insertion qui l'oublie, ce qui a
 * son mérite. Mais l'échec tomberait au dépôt d'une pièce par un candidat, et
 * la panne coûterait plus que le silence : ici, oublier la colonne donne un état
 * sûr, fermé, et corrigé au prochain passage de la commande de rattrapage.
 *
 * **Aucune ligne existante n'est touchée.** Changer un défaut ne réécrit pas le
 * passé, et il ne faut pas qu'il le fasse : les pièces déjà en `QUARANTINE` le
 * sont parce qu'une analyse les y a mises.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->defaut(AttachmentScanStatus::PENDING->value);
    }

    public function down(): void
    {
        $this->defaut(AttachmentScanStatus::QUARANTINE->value);
    }

    /**
     * Écrit en SQL, et non par le schema builder.
     *
     * `$table->string(...)->default(...)->change()` reconstruit la définition
     * complète de la colonne à partir de ce qu'on lui redonne : un attribut
     * omis — la longueur, la nullabilité — serait silencieusement remis à sa
     * valeur par défaut. Pour ne changer qu'un défaut, `ALTER COLUMN ... SET
     * DEFAULT` ne touche que ce qu'on lui nomme.
     */
    private function defaut(string $valeur): void
    {
        DB::statement("ALTER TABLE attachments ALTER COLUMN scan_status SET DEFAULT '{$valeur}'");
    }
};
