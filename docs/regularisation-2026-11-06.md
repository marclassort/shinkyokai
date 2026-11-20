# Mise en production, régularisation de la commande #52 et activation du cron

## Ce qui s'est passé (6 novembre 2026)

Un lot de 107 adhérents (complément du club Shinkyobudo) a été traité dans la requête web de `/paiement-succes`.
À ≈ 3,4 s par carte (PDF + e-mail), il aurait fallu ≈ 6 min ; l'hébergeur coupe à ≈ 3 min (503 après 193 s). Résultat :

- 56 cartes envoyées (ligne 1 = SKK0195 … ligne 56 = SKK0250), **rien enregistré en base** ;
- 51 adhérents (lignes 57 à 107) sans carte ; commande #52 restée « en-cours » (paiement de 535 € encaissé) ;
- deux adhérents arrivés ensuite (commande #53) ont reçu SKK0195 et SKK0196, déjà imprimés sur des cartes du lot.

## Numérotation cible

| Qui | Numéros | Action |
|---|---|---|
| Lignes 1 à 56 | SKK0195 → SKK0250 | enregistrés avec le numéro déjà imprimé, aucun e-mail |
| Lignes 57 à 107 | SKK0251 → SKK0301 | enregistrés, carte envoyée |
| Les 2 adhérents arrivés ensuite (commande #53) | SKK0302 / SKK0303 | renumérotés ; **aucune carte envoyée** (ils n'avaient jamais reçu l'ancienne), voir plus bas |

## Comment fonctionne le nouvel envoi des cartes

Chaque adhérent et chaque club a désormais un état d'envoi (« carte envoyée le », nombre d'essais, dernière erreur),
visible dans EasyAdmin. Une carte est **en attente** tant qu'elle n'est pas marquée envoyée. Le résultat est
enregistré **après chaque carte** : une coupure du processus ne perd et ne duplique rien.

Deux modes, via la variable `LICENCE_CARDS_MODE` (dans `.env.local` du serveur) :

- `inline` (**défaut**) : les cartes partent juste après l'inscription, comme avant, mais avec suivi. Les cartes qui
  n'ont pas pu partir restent en attente et repartent à la prochaine exécution.
- `cron` : l'inscription répond tout de suite et n'envoie plus rien ; la commande `app:licences:envoyer`, lancée
  chaque minute par cron, envoie les cartes par petits lots. **Plus aucune limite de temps de l'hébergeur.**

Tant que le cron n'est pas installé, ne passez PAS en mode `cron` (aucune carte ne partirait).

---

## Procédure (dans cet ordre)

### 0. Avant tout

Changez le mot de passe Gmail qui figurait dans `.env` (dépôt public) et supprimez la ligne.

### 1. Base de données (AVANT de déployer le code)

Ces modifications sont compatibles avec l'ancien code : on peut les faire sans interrompre le site.

```sql
-- État actuel (pour mémoire : la colonne est NOT NULL, ce qui fait échouer les inscriptions « arts culturels »)
SHOW COLUMNS FROM `member` LIKE 'licence_number';

ALTER TABLE `member`
  MODIFY licence_number VARCHAR(255) DEFAULT NULL,
  ADD card_sent_at DATETIME DEFAULT NULL,
  ADD card_attempts SMALLINT DEFAULT 0 NOT NULL,
  ADD card_last_attempt_at DATETIME DEFAULT NULL,
  ADD card_last_error VARCHAR(255) DEFAULT NULL,
  ADD card_replaces VARCHAR(20) DEFAULT NULL;

ALTER TABLE `club`
  ADD card_sent_at DATETIME DEFAULT NULL,
  ADD card_attempts SMALLINT DEFAULT 0 NOT NULL,
  ADD card_last_attempt_at DATETIME DEFAULT NULL,
  ADD card_last_error VARCHAR(255) DEFAULT NULL,
  ADD card_replaces VARCHAR(20) DEFAULT NULL;

-- RATTRAPAGE OBLIGATOIRE : tous les adhérents et clubs existants ont déjà reçu leur carte.
-- Sans cela, ils seraient considérés « en attente » et recevraient leur carte une deuxième fois.
UPDATE `member` SET card_sent_at = NOW() WHERE card_sent_at IS NULL;
UPDATE `club`   SET card_sent_at = NOW() WHERE card_sent_at IS NULL;
```

> `member` est un mot réservé de MySQL 8 : toujours l'écrire entre accents graves.

### 2. Déploiement

1. Déployer le code (aucune nouvelle dépendance : pas de `composer install` nécessaire).
2. Dans `.env.local` du serveur : `LICENCE_FIRST_AVAILABLE=304` (aucun numéro inférieur ne sera attribué),
   puis vider le cache : `php bin/console cache:clear`.
3. **Relancer immédiatement les deux `UPDATE` de rattrapage** de l'étape 1 : ils ne touchent que les lignes encore
   vides, et couvrent les inscriptions arrivées entre l'étape 1 et le déploiement.
4. Vérifier que rien n'est en attente : `php bin/console app:licences:envoyer --status` doit afficher **0 / 0 / 0**.
   (Si ce n'est pas 0, ne continuez pas : `app:licences:envoyer` refuse de toute façon de démarrer au-delà de 150
   cartes en attente, pour éviter un envoi massif accidentel.)
5. **Passer provisoirement en mode `cron` AVANT la régularisation** : dans `.env.local`, ajouter
   `LICENCE_CARDS_MODE=cron`, puis `php bin/console cache:clear`. Dans le mode par défaut (`inline`), toute
   inscription envoie, après sa réponse, TOUTES les cartes en attente : la régularisation avec `--no-mail` en laisse 51,
   qui partiraient seules à la prochaine inscription, sans passer par l'essai de l'étape 4. En mode `cron`, une
   inscription enregistre tout mais n'envoie rien ; vous lancez les envois à la main (étape 4) puis le cron prend le
   relais. Une inscription faite pendant cette fenêtre reste simplement « en attente » (elle apparaît dans `--status`).

### 3. Régularisation de la commande #52

Récupérer le fichier exact traité le 6 novembre : l'ancienne version l'a enregistré dans `public/uploads/`
(nom aléatoire en `.xlsx`, daté du 06/11 vers 21:57 UTC). Le copier dans `var/` (jamais dans `public/`).
À défaut, la copie locale `lot1-skk.xlsx` doit avoir le même ordre de lignes.

**Simulation** (ne modifie rien, n'envoie rien) :

```
php bin/console app:licences:regulariser 52 var/lot1-skk.xlsx \
    --club-number=SHIN0011 --first-licence=195 --already-sent=56 --swap-names=31 --no-replacement-cards
```

`--swap-names=31` remet dans le bon ordre le prénom et le nom de la ligne 31 (saisis à l'envers dans le fichier du
club) : la base de données contiendra le prénom et le nom dans le bon ordre, le nom de famille en capitales. Sa carte a
été envoyée le 6 novembre avec les deux inversés ; **décision : elle n'est pas renvoyée** (l'option `--resend-rows=31` existe mais
ne doit pas être utilisée ici).

`--no-replacement-cards` : les deux adhérents arrivés après l'incident (commande #53) sont renumérotés (SKK0302 et
SKK0303) **sans qu'aucune carte ne leur soit envoyée** : ils n'avaient jamais reçu l'ancienne. Le plan affiche
« NON (option --no-replacement-cards… ) » dans la colonne « Dans la file d'envoi ». Ils n'ont donc actuellement
aucune carte : pour leur envoyer plus tard une carte normale avec leur nouveau numéro (sans mention de remplacement),
remettre leur carte « en attente » puis lancer `app:licences:envoyer` :
```sql
UPDATE `member` SET card_sent_at = NULL, card_attempts = 0, card_last_attempt_at = NULL, card_last_error = NULL
 WHERE licence_number IN ('SKK0302', 'SKK0303');
```

À contrôler dans le tableau : **ligne 1 = SKK0195** et **ligne 56 = SKK0250** (numéros vus sur les
cartes) ; 2 adhérents renumérotés (SKK0302 et SKK0303), tous deux « NON (option --no-replacement-cards… ) » dans la colonne « Dans la file d'envoi ».

**Application, sans envoi** (tout est enregistré, les 51 cartes restent en attente) :

```
php bin/console app:licences:regulariser 52 var/lot1-skk.xlsx \
    --club-number=SHIN0011 --first-licence=195 --already-sent=56 --swap-names=31 --no-replacement-cards \
    --already-sent-at="2026-11-06 22:03" --no-mail --execute
```

(22:03 UTC = 23h03 à Paris, heure du dernier envoi relevé.)

### 4. Essai de l'envoi AVANT d'activer le cron

Les 51 nouvelles cartes attendent maintenant dans la file. Dans l'ordre :

```
php bin/console app:licences:envoyer --status
```
→ « En attente : 51 ».

```
php bin/console app:licences:envoyer --dry-run
```
→ liste des cartes qui partiraient, sans rien envoyer.

```
php bin/console app:licences:envoyer --test-recipient=votre.adresse@exemple.fr
```
→ envoie **5 cartes au maximum, à votre adresse seulement**, et **ne marque rien comme envoyé**. Ouvrez les e-mails :
le PDF s'affiche-t-il correctement (logo, polices) ? Les adhérents ne
reçoivent rien.

```
php bin/console app:licences:envoyer --limit=3
```
→ envoie **réellement** 3 cartes aux vrais adhérents (les premières de la file). Vérifiez dans EasyAdmin que
« Carte envoyée le » est renseigné pour ces 3 adhérents, et relancez la commande : elle ne renvoie pas les mêmes.

```
php bin/console app:licences:envoyer --limit=60
```
→ envoie le reste (≈ 3 min, sans limite en ligne de commande). Puis `--status` : « En attente : 0 ».

### 5. Contrainte d'unicité : NE PAS la créer tant qu'il existe des doublons historiques

Le contrôle suivant, lancé en production après la régularisation, a renvoyé **138 numéros de licence en double** :

```sql
SELECT COUNT(*) FROM (SELECT licence_number FROM `member` WHERE licence_number IS NOT NULL GROUP BY licence_number HAVING COUNT(*) > 1) d;
```

Ces doublons existaient AVANT la régularisation : les anciennes commandes d'import
(`ImportClubAndMemberLicencesCommand`, `ImportClubAndMembersDataOnlyCommand`) numérotaient les adhérents avec un
compteur qui repartait à `SKK0001` pour chaque club importé. Ils se situent donc dans les petits numéros. Pour le
vérifier :

```sql
-- plage des numéros en double (le plus petit, le plus grand, combien)
SELECT MIN(licence_number), MAX(licence_number), COUNT(*) FROM (SELECT licence_number FROM `member` WHERE licence_number IS NOT NULL GROUP BY licence_number HAVING COUNT(*) > 1) d;
-- touchent-ils le lot régularisé (SKK0195 à SKK0303) ? DOIT renvoyer 0
SELECT COUNT(*) FROM (SELECT licence_number FROM `member` WHERE licence_number BETWEEN 'SKK0195' AND 'SKK0303' GROUP BY licence_number HAVING COUNT(*) > 1) d;
```

Conséquence : `CREATE UNIQUE INDEX uniq_member_licence_number ...` échouerait, et on ne doit pas l'exécuter. La protection
contre de nouveaux doublons repose sur `LicenceNumberGenerator` (plus grand numéro existant + 1, plancher
`LICENCE_FIRST_AVAILABLE`), qui ne réattribue jamais un numéro. Les doublons historiques sont un chantier à part
(des cartes ont été émises avec ces numéros) : à décider avec le club avant toute renumérotation.

`php bin/console doctrine:schema:update --dump-sql` continuera à proposer la création de cet index : l'ignorer.
**N'utilisez jamais `--force`.**

### 6. Tâche planifiée horaire (filet de sécurité) : hébergement Gandi

Sur Gandi Simple Hosting, il n'y a pas de cron classique mais **anacron**, dont l'intervalle minimal est **1 heure**
(`1@hourly`). Un passage par minute est donc impossible : le mode `cron` (qui n'envoie les cartes que par la tâche
planifiée) ferait attendre jusqu'à une heure la carte d'un nouvel inscrit. La bonne configuration est la combinaison :

- **mode `inline`** (`LICENCE_CARDS_MODE=inline`, ou variable absente) : la carte part juste après l'inscription ;
- **tâche horaire** : elle envoie les cartes restées en attente (envoi interrompu par la limite de l'hébergeur,
  e-mail en échec à retenter, jusqu'à 5 essais espacés de 10 minutes).

L'envoi après inscription et la tâche partagent le même **verrou** : s'ils se croisent, l'un attend l'autre, et une
carte ne part jamais deux fois.

Dans l'interface Gandi (page « anacrontab » de l'instance), ajouter à la fin du fichier (une seule ligne) :

```
1@hourly 0 cartes-licences /opt/php-8.3/bin/php /srv/data/web/vhosts/shinkyokai.com/htdocs/bin/console app:licences:envoyer --limit=100 --time-limit=600 >> /srv/data/web/vhosts/shinkyokai.com/htdocs/var/log/cron-cartes.log 2>&1
```

(Le chemin du PHP se relève avec `which php` ; le deuxième champ, `0`, est ignoré mais obligatoire ; le nom
`cartes-licences` doit être unique.) Contrôle : après environ une heure, `tail -3 var/log/cron-cartes.log` doit montrer
une ligne « Aucune carte à envoyer maintenant… ». En attendant, la même commande tapée à la main fait la même chose.

**Retour en arrière** : supprimer la ligne dans la page anacrontab. Le mode `inline` fonctionne sans la tâche, la seule
perte étant l'envoi automatique des cartes restées en attente (à lancer alors à la main).

### Surveillance

- `php bin/console app:licences:envoyer --status` : en attente / nouvel essai prévu / abandonnées.
- Une carte en échec est retentée après 10 minutes, jusqu'à 5 essais, puis **abandonnée** : elle apparaît dans
  « Abandonnées » avec son erreur (EasyAdmin, fiche de l'adhérent). Après correction de l'adresse e-mail, la remettre
  en attente :
  ```sql
  UPDATE `member` SET card_sent_at = NULL, card_attempts = 0, card_last_attempt_at = NULL, card_last_error = NULL WHERE id = 123;
  ```
- Le journal `var/log/cron-cartes.log` reçoit une ligne par heure (24 par jour).
- Gmail limite le volume d'envoi (≈ 500 e-mails par jour pour un compte gratuit) : pour un très gros lot, utiliser
  `--limit=20` dans la ligne du cron.

## Règle sur les prénoms

Un prénom est **toujours enregistré avec une majuscule au début, et jamais tout en capitales** (la même règle
s'applique après un trait d'union, une espace ou une apostrophe) :

| Saisi | Enregistré |
|---|---|
| `karim` | `Karim` |
| `LUCAS`, `SACHA` | `Lucas`, `Sacha` |
| `anne-sophie`, `ANNE-SOPHIE` | `Anne-Sophie` |
| `McKenzie` (casse mixte) | `McKenzie` (conservé) |

La règle s'applique à la lecture des fichiers de clubs, aux formulaires, à EasyAdmin (à l'enregistrement de l'adhérent)
et aux cartes PDF. Elle ne modifie pas les adhérents déjà en base. Les noms de famille ne sont pas modifiés.

Lors d'une inversion nom/prénom (`--swap-names`), le nouveau nom de famille est écrit en capitales
(par exemple `DURAND`), le nouveau prénom avec la règle ci-dessus (par exemple `Camille`).

## Vérifications après régularisation

- `member` : 107 adhérents pour Shinkyobudo 2026-2027 (+ 1 adhérent déjà affilié), plus grand numéro SKK0303 ; ligne 31 corrigée (prénom et nom dans le bon ordre, SKK0225).
- Commande #52 : « Payée », club Shinkyobudo, montant 535 € inchangé.
- Prochaine inscription : SKK0304.
- Supprimer le fichier d'adhérents de `var/` et de `public/` (données personnelles).

---

## Simuler tout cela en local, sans aucune action réelle

```
vendor/bin/phpunit --testsuite simulation
```

Trois scénarios sont joués avec le fichier `public/lot1-skk.xlsx` (ou `SIMULATION_FILE=/chemin/fichier.xlsx`) :

1. **Complément avec envoi immédiat** : formulaire, commande, paiement Stripe simulé, retour de paiement, base de données, PDF, e-mails.
2. **Même parcours en mode cron** : inscription instantanée, puis envoi par lots de 40 (`--status`, `--dry-run`, `--test-recipient`).
3. **Régularisation de la commande #52** : simulation, application sans envoi, essai, envoi, puis relance par erreur.

Ce qui est garanti : base **SQLite en mémoire** (votre base locale n'est jamais ouverte, même si `DATABASE_URL` est
défini dans votre shell ou votre IDE), e-mails **interceptés**, Stripe **simulé**, fichier source jamais modifié, copies
temporaires supprimées. Un arrêt de sécurité se déclenche si les tests visaient une autre base.

Chaque e-mail est examiné : expéditeur, destinataire, pièce jointe `licence.pdf`, et le **texte réel du PDF** (nom,
licence, club) est relu et comparé à la base et au fichier. Il faut `pdftotext`/`pdfinfo` (`brew install poppler`).

- **Journal ligne par ligne** : pour chaque ligne du fichier, ce qui serait écrit en base (toutes les informations),
  les transformations appliquées (prénom mis en forme, nom et prénom inversés), et l'e-mail (adresse, objet,
  PDF relu) ou la raison de son absence (carte déjà envoyée, en attente). Il est exporté en **CSV pour Excel** :
  `var/simulation/journal-par-ligne-*.csv` (25 colonnes, une ligne par adhérent, plus les adhérents modifiés hors
  fichier comme les cartes de remplacement).
- **Modifications de la base avant / après** : pour chaque opération, ce qui est ajouté, modifié (colonne par colonne,
  ancienne → nouvelle valeur) ou inchangé pour les clubs, les adhérents et les commandes.
- Pour ne voir que la régularisation : `vendor/bin/phpunit --testsuite simulation --filter RegularisationSimulationTest`.
- Rapport complet : `var/simulation/rapport-AAAAMMJJ-HHMMSS.log` (données personnelles : à supprimer ensuite ;
  `var/` est ignoré par git). `SIMULATION_VERBOSE=0` rend la console moins bavarde.
- 3 cartes d'exemple par scénario sont conservées dans `var/simulation/pdf/` (`SIMULATION_KEEP_PDFS=0` pour désactiver).
- Les `[ATTENTION]` ne sont pas des erreurs de l'application : ce sont des données douteuses du fichier.
