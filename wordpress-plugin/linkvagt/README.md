# LinkVagt WordPress-plugin

LinkVagt installeres på ét centralt WordPress-site og scanner andre websites
eksternt. Produktionsadgang sker med Google OpenID Connect. Der er ingen
offentlig registrering.

## Sikker konfiguration

Tilføj værdierne i `wp-config.php` over linjen `/* That's all, stop editing! */`:

```php
define('LINKVAGT_OWNER_EMAIL', 'stig@su-media.dk');
define('LINKVAGT_GOOGLE_CLIENT_ID', '...');
define('LINKVAGT_GOOGLE_CLIENT_SECRET', '...');
define(
    'LINKVAGT_GOOGLE_REDIRECT_URI',
    'https://su-media.dk/wp-json/linkvagt/v1/auth/google/callback'
);
define('LINKVAGT_ENCRYPTION_KEY', 'base64-kodet-32-byte-nøgle');
// Valgfrit: brug en mappe uden for webroden.
define('LINKVAGT_BACKUP_DIR', '/sikker/sti/linkvagt-backups');
```

Hemmeligheder må ikke gemmes i pluginets kildekode eller versionsstyring.

På Local kan en allerede logget WordPress-ejer bruge appen, fordi Google ikke
accepterer `.local` som produktionscallback. Denne bootstrapadgang er teknisk
begrænset til WordPress-miljøtypen `local` og virker ikke i produktion.

## Automatisk scanning

Pluginet planlægger som standard en ugentlig batch mandag kl. 02.00 i
WordPress' tidszone. Ugedag og klokkeslæt kan ændres under LinkVagts
indstillinger. På hvert website kan automatisk scanning sættes til ugentligt,
hver 14. dag, månedligt eller deaktiveres, så websitet kun scannes manuelt.
Websites scannes sekventielt, og der sendes én samlet mail, når hele batchen er
færdig. Manuelle scanninger rapporteres fortsat separat.

På Simply.com bør `wp-cron.php` fortsat kaldes af et rigtigt cronjob for
WordPress' øvrige planlagte opgaver. LinkVagts dedikerede worker-URL, som vises
under indstillinger, skal desuden kaldes hvert minut. Deaktiver kun WordPress'
besøgsudløste cron med `DISABLE_WP_CRON`, når det almindelige eksterne cronjob
er oprettet og kontrolleret.
