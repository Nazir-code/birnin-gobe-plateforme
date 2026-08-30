<?php

namespace App\Domain\Administration;

/**
 * Les neuf domaines paramétrables du §9.2.
 *
 * Le tableau du cahier des charges est repris ligne pour ligne, y compris les
 * domaines qu'aucun écran ne sait encore administrer. C'est le même parti pris
 * que pour les familles d'indicateurs : un domaine absent de l'écran se lirait
 * comme un domaine qui n'existe pas, et le comité de pilotage croirait avoir
 * tout réglé.
 *
 * `etat()` dit lequel des trois cas s'applique :
 *
 *  - **administrable** — un écran existe, et son adresse est donnée ;
 *  - **partiel** — une partie du domaine est réglable, le reste ne l'est pas.
 *    C'est le cas le plus dangereux à taire : croire qu'on a paramétré
 *    l'évaluation parce qu'on a fixé le nombre d'évaluateurs, alors que les
 *    critères et leurs poids ne sont pas administrables ;
 *  - **absent** — rien n'est réglable, et la raison est dite.
 *
 * Cette classe ne décrit **que** l'état de l'outillage. Elle ne stocke aucun
 * paramètre : ce que chaque domaine contient vit dans `campaigns.settings` ou
 * dans les tables concernées.
 */
enum SettingsDomain: string
{
    case CAMPAGNE = 'CAMPAGNE';
    case ELIGIBILITE = 'ELIGIBILITE';
    case THEMATIQUES = 'THEMATIQUES';
    case FORMULAIRE = 'FORMULAIRE';
    case EVALUATION = 'EVALUATION';
    case COMMUNICATION = 'COMMUNICATION';
    case PUBLICATION = 'PUBLICATION';
    case UTILISATEURS = 'UTILISATEURS';
    case CONSERVATION = 'CONSERVATION';

    public function label(): string
    {
        return match ($this) {
            self::CAMPAGNE => 'Campagne',
            self::ELIGIBILITE => 'Éligibilité',
            self::THEMATIQUES => 'Thématiques',
            self::FORMULAIRE => 'Formulaire',
            self::EVALUATION => 'Évaluation',
            self::COMMUNICATION => 'Communication',
            self::PUBLICATION => 'Publication',
            self::UTILISATEURS => 'Utilisateurs',
            self::CONSERVATION => 'Conservation',
        };
    }

    /** Le contenu du domaine, repris du tableau du §9.2. */
    public function perimetre(): string
    {
        return match ($this) {
            self::CAMPAGNE => 'Nom, édition, statut, dates, fuseau horaire, compte à rebours, période de grâce, domaine, contacts et textes légaux.',
            self::ELIGIBILITE => 'Âge et date de référence, nationalité/résidence, zones, types de candidats, taille d’équipe, restrictions et motifs d’exclusion.',
            self::THEMATIQUES => 'Axes, sous-thèmes, défis, communes, descriptions, experts, quotas ou nombres de short-list.',
            self::FORMULAIRE => 'Sections, champs, aide, obligatoire/conditionnel, longueur, options, ordre, pièces, formats et tailles.',
            self::EVALUATION => 'Phases, critères, sous-critères, poids, échelles, seuils, nombre d’évaluateurs, agrégation et règles d’égalité.',
            self::COMMUNICATION => 'Modèles email/SMS, expéditeur, langue, événements, planification et validation.',
            self::PUBLICATION => 'Pages, actualités, FAQ, ressources, partenaires, résultats et date de mise en ligne.',
            self::UTILISATEURS => 'Super administrateur, gestionnaire, vérificateur, évaluateur, jury, observateur/auditeur, communication et support ; périmètres et dates d’accès.',
            self::CONSERVATION => 'Durée par catégorie de données, anonymisation, archivage, suppression et autorisations d’export.',
        };
    }

    public function etat(): SettingsState
    {
        return match ($this) {
            self::CAMPAGNE, self::ELIGIBILITE => SettingsState::ADMINISTRABLE,
            self::EVALUATION, self::COMMUNICATION => SettingsState::PARTIEL,
            default => SettingsState::ABSENT,
        };
    }

    /**
     * Ce qui est réellement réglable, ou pourquoi rien ne l'est.
     *
     * Chaque raison nomme la dépendance manquante, pas un « à faire » : un
     * domaine sans écran d'administration l'est parce que la fonction qu'il
     * paramètre n'existe pas encore, et le dire évite de croire qu'il suffirait
     * d'ajouter un formulaire.
     */
    public function precision(): string
    {
        return match ($this) {
            self::CAMPAGNE => 'Nom, code, statut et calendrier sont réglables. Compte à rebours, période de grâce, contacts et textes légaux ne le sont pas encore : aucun écran public ne les lit.',
            self::ELIGIBILITE => 'Les cinq règles d’ADR-007 sont réglables par campagne. Les motifs d’exclusion ne le sont pas : ils appartiennent au contrôle d’admissibilité, où ils sont codifiés par la grille du §10.2.',
            self::EVALUATION => 'Le nombre minimal d’évaluations et le seuil d’écart sont réglables. Les critères, leurs poids et l’échelle du §11.2 ne le sont pas : tant qu’aucune notation n’existe, les rendre configurables publierait un réglage que rien ne lit.',
            self::THEMATIQUES => 'Les quatre axes sont un référentiel du code (`ProjectTheme`), pas une donnée. Les rendre administrables suppose de décider ce qu’il advient des dossiers déjà rattachés à un axe supprimé.',
            self::FORMULAIRE => 'Les neuf sections et leurs champs sont décrits par le domaine, et leur validation serveur en dépend. Un formulaire administrable suppose un moteur de champs dynamiques, pas un écran de plus.',
            self::COMMUNICATION => 'Les six événements du §8.3 sont envoyés par courriel, et chaque envoi laisse une trace. Ce qui manque reste réel : aucun fournisseur SMS n’est choisi, aucune adresse de secrétariat n’est configurée, et les modèles ne sont pas éditables sans code — ils vivent dans « app/Notifications ».',
            self::PUBLICATION => 'Le CMS n’existe pas : les contenus publics sont encore portés par le code et `i18n/fr.ts`.',
            self::UTILISATEURS => 'Les comptes internes sont provisionnés en ligne de commande (ADR-006). Une administration des rôles suppose d’abord d’arbitrer les périmètres et les dates d’accès du §9.2.',
            self::CONSERVATION => 'Aucune purge n’est implémentée. Une durée de conservation affichée mais jamais appliquée serait une promesse fausse, opposable en cas de contrôle.',
        };
    }

    /**
     * @return array{value: string, label: string, scope: string, state: string, stateLabel: string, detail: string}
     */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'label' => $this->label(),
            'scope' => $this->perimetre(),
            'state' => $this->etat()->value,
            'stateLabel' => $this->etat()->label(),
            'detail' => $this->precision(),
        ];
    }
}
