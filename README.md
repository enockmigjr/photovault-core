# PhotoVault Core

PhotoVault Core contient la logique metier media de PhotoVault: CPT, taxonomies, thumbnails/previews, endpoint secure-image, downloads controles, collections protegees, demandes d'acces, grants, stockage prive et audit media.

## Responsabilites

- Enregistrer `media_item`, `media_folder`, `media_category` et `media_tag`.
- Ajouter les capabilities PhotoVault aux administrateurs.
- Servir les listes media via REST sans exposer les originaux HD.
- Servir les previews et downloads via `/wp-json/photovault/v1/secure-image`.
- Bloquer les medias prives si l'utilisateur n'est pas owner, admin/media manager ou beneficiaire d'un grant.
- Filigraner les previews protegees pour les visiteurs non privilegies.
- Mettre en cache les previews filigranees pour limiter le cout GD.
- Exposer des options admin bornees pour texte, image, opacite, densite et qualite JPEG du filigrane.
- Deplacer les originaux proteges/prives vers un stockage prive quand le traitement est applique.
- Journaliser previews, downloads, refus, demandes et grants.
- Fournir une bibliotheque personnelle de favoris, un historique de telechargements et les acces du compte sans fuite entre utilisateurs.
- Gerer les demandes de shootings privees, leur ownership, leurs transitions serveur et leurs notifications transactionnelles.
- Gerer les categories de contact public, la notification studio et l'accuse de reception du visiteur.
- Envoyer les notifications multipart des demandes d'acces: accuse client, alerte studio avec `Reply-To` et decisions approuvee/refusee.
- Conserver l'approbation d'acces atomique entre creation du grant et mise a jour du statut.
- Fournir un espace d'import administrateur avec progression fichier par fichier et edition immediate des metadonnees.

## Capabilities

- `photovault_manage_platform`
- `photovault_manage_media`
- `photovault_view_private_media`
- `photovault_manage_settings`
- `photovault_manage_shootings`

`manage_options` reste accepte comme fallback administrateur dans les helpers PhotoVault.

## Tables

- `{$wpdb->prefix}photovault_access_requests`
- `{$wpdb->prefix}photovault_access_grants`
- `{$wpdb->prefix}photovault_media_audit`
- `{$wpdb->prefix}photovault_favorites`

## Options et metas importantes

- `photovault_core_version`
- `photovault_watermark_text`
- `photovault_watermark_opacity`
- `photovault_watermark_spacing`
- `photovault_watermark_quality`
- `photovault_watermark_image_id`
- `is_protected`
- `_photovault_original_url`
- `_photovault_private_original_path`
- `_photovault_private_original_secured_at`
- `_photovault_shooting_type`, `_photovault_shooting_date`, `_photovault_shooting_location`
- `_photovault_shooting_contact_name`, `_photovault_shooting_contact_email`, `_photovault_shooting_contact_phone`
- `_photovault_shooting_status`, `_photovault_shooting_updated_at`

## Endpoints et actions

- `GET /wp-json/photovault/v1/media`
- `POST /wp-json/photovault/v1/media/upload`
- `POST/PUT/PATCH /wp-json/photovault/v1/media/{id}`
- `GET /wp-json/photovault/v1/secure-image`
- `GET /wp-json/photovault/v1/favorites`
- `POST /wp-json/photovault/v1/favorites/{id}`
- `DELETE /wp-json/photovault/v1/favorites/{id}`
- `admin_post_photovault_update_access_request_status`
- `admin_post_photovault_secure_existing_originals`
- `admin_post_photovault_create_shooting`
- `admin_post_photovault_shooting_transition`

## WP-CLI

```bash
wp photovault secure-originals --limit=25
wp photovault seed_demo
```

La premiere commande traite les originaux proteges/prives existants par lots. La seconde installe une seule fois un dataset de demonstration riche en reutilisant les pieces jointes locales: medias, articles, comptes, demandes d'acces, shootings, audits, abonnes et campagnes lorsque les autres kits sont actifs.

## Filtres publics

- `photovault_max_upload_bytes`
- `photovault_max_upload_dimension`
- `photovault_max_upload_files`
- `photovault_private_originals_dir`
- `photovault_protected_preview_cache_dir`
- `photovault_shooting_types`

## Verification minimale

1. Activer le plugin et verifier les tables DB.
2. Confirmer que les pages liste utilisent les variantes `card`/`preview`.
3. Tester `secure-image` avec anonyme, user, owner, media manager et admin.
4. Tester un media prive sans grant puis avec grant.
5. Verifier que le serveur web refuse l'acces direct a `wp-content/photovault-private/`.
6. Lancer `wp photovault secure-originals --limit=25` si WP-CLI est disponible.
7. Executer `wp eval-file tests/runtime-user-library.php` pour verifier favoris, isolation, historique, acces et permissions REST.
8. Executer `wp eval-file tests/runtime-shootings.php` pour verifier validation, ownership, transitions, administration et e-mails.
9. Executer `wp eval-file tests/runtime-media-management.php` pour verifier metadonnees, tags, isolation des comptes et acces a l'import.
10. Executer `wp eval-file tests/runtime-media-authorization.php` pour verifier roles, grants, ID guessing, pagination privee, nonces et refus de telechargement.
11. Executer `wp eval-file tests/runtime-email-notifications.php` pour verifier layout HTML, texte alternatif, `Reply-To`, notifications d'acces et remise SMTP.

## Documentation liee

Voir dans le depot theme principal:

- `doc/rest-ajax-inventory.md`
- `doc/capabilities-matrix.md`
- `doc/plugin-surfaces.md`
- `doc/adr/ADR-002-protected-media-storage.md`
