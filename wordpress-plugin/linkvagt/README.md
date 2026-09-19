# LinkVagt WordPress-plugin

LinkVagt installeres på ét centralt WordPress-site og scanner andre websites
eksternt. Adgang sker med WordPress' eget login. Der er ingen offentlig
registrering.

## Login og to-faktor

LinkVagt har ingen selvstændig loginformular. Gæsteskærmen sender brugeren til
`wp-login.php` og videre tilbage til appsiden bagefter. Alt hvad der allerede
beskytter WordPress-loginet — to-faktor, brute force-beskyttelse,
adgangskodepolitik — beskytter dermed også LinkVagt. Et 2FA-plugin på sitet
gælder automatisk her.

Til gengæld er `wp-login.php` nu den eneste angrebsflade mod LinkVagt. Den bør
have både to-faktor og en form for rate limiting.

## Roller og adgang

Adgang kræver en aktiv række i `wp_linkvagt_members`. Rækken oprettes
automatisk, første gang en berettiget WordPress-bruger åbner appsiden:

1. Brugerens mail matcher `LINKVAGT_OWNER_EMAIL` → rollen `owner`.
2. Der findes en gyldig, uindløst invitation til mailen → invitationens rolle.
3. Medlemstabellen er tom, og brugeren er WordPress-administrator → `owner`.
   Denne bootstrap lukker sig selv, så snart der findes ét medlem.

Alle andre får besked om manglende adgang. Capabilities synkroniseres ved hver
sidevisning, så en rolleændring i tabellen slår igennem med det samme — også
nedgraderinger og spærring (`status` ≠ `active`).

## Sikker konfiguration

Tilføj værdierne i `wp-config.php` over linjen `/* That's all, stop editing! */`:

```php
define('LINKVAGT_OWNER_EMAIL', 'stig@su-media.dk');
define('LINKVAGT_ENCRYPTION_KEY', 'base64-kodet-32-byte-nøgle');
// Valgfrit: brug en mappe uden for webroden.
define('LINKVAGT_BACKUP_DIR', '/sikker/sti/linkvagt-backups');
```

Hemmeligheder må ikke gemmes i pluginets kildekode eller versionsstyring.

## Automatisk scanning

Pluginet planlægger som standard en ugentlig batch mandag kl. 02.00 i
WordPress' tidszone. Ugedag og klokkeslæt kan ændres under LinkVagts
indstillinger. På hvert website kan automatisk scanning sættes til ugentligt,
hver 14. dag, månedligt eller deaktiveres, så websitet kun scannes manuelt.
Websites scannes sekventielt, og der sendes én samlet mail, når hele batchen er
færdig. Manuelle scanninger sender ingen mail.

På Simply.com bør `wp-cron.php` fortsat kaldes af et rigtigt cronjob for
WordPress' øvrige planlagte opgaver. LinkVagts dedikerede worker-URL, som vises
under indstillinger, skal desuden kaldes hvert minut. Deaktiver kun WordPress'
besøgsudløste cron med `DISABLE_WP_CRON`, når det almindelige eksterne cronjob
er oprettet og kontrolleret.

## Ignorerede og godkendte links

- **Ignorer**: linket er et kendt problem. Det kan gælde hele hjemmesiden eller
  én kildeside. En regel for én kildeside tilsidesætter kun fundet, når alle
  sider med linket er dækket.
- **Virker**: linket virker, men LinkVagt kan ikke se det, fx fordi serveren
  mangler et mellemliggende certifikat (cURL error 60). Godkendelsen gælder
  kun det svar, der blev godkendt. Svarer linket senere anderledes, vises det
  igen som et problem.

Tilsidesatte links gemmes stadig ved hver scanning, men tæller ikke med i
problemtal, oversigt eller mails.
