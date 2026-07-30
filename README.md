# LinkVagt

LinkVagt er et letvaegts-dashboard til manuelt startede kontroller af doede links, redirects og usikre HTTP-svar. Appen bruger kun funktioner, der er indbygget i Node.js 24, og kraever derfor ingen installation af npm-pakker.

## Start lokalt

Den nemmeste metode er at dobbeltklikke paa `Start LinkVagt.cmd`. Den starter appen i baggrunden og aabner dashboardet, naar det er klar. Hvis LinkVagt allerede koerer, aabnes dashboardet uden at starte en ekstra kopi.

Alternativt kan appen startes fra PowerShell:

```powershell
cd "C:\Users\stig-\Local Sites\LinkVagt"
node src/server.js
```

Dashboardet er derefter tilgaengeligt paa `http://127.0.0.1:4173`.

Data gemmes som standard i `data/linkvagt.db`. Placeringen kan aendres med miljoevariablen `LINKVAGT_DB`.

Scanninger startes altid manuelt fra dashboardet. Det giver ingen hostingudgift, naar appen kun koerer paa din egen computer.

## Funktioner i fase 1

- Ubegrænset antal hjemmesider uden en indbygget produktgrænse
- Manuel scanning af hele websitet, en enkelt side eller kun et enkelt link
- Sitemap, sitemap-indeks og automatisk fallback-crawl
- Deduplikering af destinationer og kontrolleret parallel scanning
- Døde links, redirects, redirect-kæder, loops, timeouts og advarsler
- Genkontrol af fejl for at reducere falske positiver
- Ignorering på hele websitet eller én bestemt kildeside
- Kompakt scanningshistorik med seneste detaljerede resultat for hvert scanningsomfang
- CSV-eksport af det aktuelle detaljerede resultat
- Mailrapport via SMTP over TLS
- Sikker, manuelt godkendt rettelse af links i almindelige WordPress-indlaeg og sider

## WordPress-linkrettelse

1. Opret en saerskilt WordPress-bruger med rettighed til at redigere indlaeg og sider.
2. Opret et applikationskodeord paa brugerens profil i WordPress.
3. Aabn `Hjemmesider` i LinkVagt, tryk paa `W`, og test forbindelsen.
4. Aabn et scanningsresultat og tryk `Ret link` ved et doedt link eller en redirect.
5. Kontroller forslaget, og godkend foerst derefter aendringen.

Foer hver skrivning gemmer LinkVagt en komplet kopi af det oprindelige indhold. Appen
kontrollerer, at siden ikke er aendret siden forhaandsvisningen, og henter indholdet igen
efter lagring. En aendring kan fortrydes fra WordPress-dialogens aendringslog.

Foerste version retter kun links i det almindelige indhold paa WordPress-indlaeg og sider.
Elementor, Divi, ACF, menuer, widgets og andre specialfelter aendres ikke automatisk.
WordPress-applikationskodeord krypteres lokalt med AES-256-GCM. Krypteringsnoeglen gemmes
som standard i `data/linkvagt.key` og skal sikkerhedskopieres sammen med databasen.

## Backup og gendannelse

LinkVagt opretter automatisk en verificeret backup ved dagens foerste opstart, efter en
fuld website-scanning og foer hver WordPress-rettelse. En manuel backup kan startes under
`Indstillinger`. Backups gemmes i `Backup` og indeholder databasen, krypteringsnoeglen og
et manifest med SHA-256-kontrolsummer.

Automatiske backups reduceres til 14 daglige og 8 ugentlige kopier. De seneste 10 backups
foer WordPress-rettelser bevares, og manuelle backups slettes ikke automatisk.

Gendannelse startes ved at dobbeltklikke paa `Gendan LinkVagt.bat`. Vaelg en verificeret
backup og skriv `GENDAN`, naar du bliver bedt om det. Den aktive database gemmes som en
noedbackup, foer den valgte kopi gendannes.

## Miljoevariabler

| Variabel | Standard | Beskrivelse |
| --- | --- | --- |
| `PORT` | `4173` | HTTP-port |
| `HOST` | `127.0.0.1` | Interface serveren lytter paa |
| `LINKVAGT_DB` | `data/linkvagt.db` | SQLite-database |
| `LINKVAGT_TIMEOUT_MS` | `12000` | Timeout for hvert HTTP-kald |
| `LINKVAGT_KEY_FILE` | `data/linkvagt.key` | Lokal noegle til kryptering af WordPress-adgang |
| `LINKVAGT_BACKUP_DIR` | `Backup` | Mappe til verificerede backups |
| `LINKVAGT_SECRET_KEY` | - | Valgfri base64-kodet 32-byte noegle i stedet for en noeglefil |

Ved drift på en server bør appen placeres bag en HTTPS-reverse proxy med login/adgangskontrol. Dashboardet har ikke brugerstyring i fase 1.

## Test

```powershell
node --test
```
