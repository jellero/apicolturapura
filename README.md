# Bioapicoltura Pura — sito vetrina

Sito vetrina statico per Bioapicoltura Pura di Gressani Orietta e Nodale Luca S.S.A., Lauco (UD).

## Struttura

- `/` — pagina vetrina principale
- `/rivista/` — landing dedicata ai lettori della rivista
- `/r/` — percorso breve e stabile pensato come destinazione del QR; reindirizza alla landing con parametri UTM
- `styles.css` — design system responsive
- `app.js` — menu mobile e animazioni progressive

## Posizionamento

Il sito mette al centro l'identità documentata dell'azienda: apicoltura biologica di montagna in Carnia, mieli monoflora e di alta quota, Presidio Slow Food e riconoscimenti nazionali.

Per evitare immagini fuorvianti, la pagina usa come fotografie aziendali solo materiali pubblicamente attribuiti a Bioapicoltura Pura; il paesaggio hero è una composizione grafica e non viene presentato come fotografia dell'apiario.

## Dominio consigliato

`apicolturapura.it`

Il QR è stato progettato per puntare a `https://apicolturapura.it/r/`. Non mandare il QR in stampa finché il dominio non è stato registrato e collegato al sito.

## Pubblicazione GitHub Pages

Il workflow `.github/workflows/pages.yml` è pronto. La connessione GitHub usata per costruire il repository non dispone del permesso amministrativo necessario per abilitare GitHub Pages sul repository.

Per la prima attivazione: Repository → Settings → Pages → Build and deployment → Source: GitHub Actions. Poi eseguire manualmente il workflow `Deploy static site to GitHub Pages`.

## Contatti riportati nel sito

- Telefono: 338 164 6743
- Email: apicolturapura@gmail.com
- Instagram: @bioapicolturapura
- Sede riportata dalle principali fonti territoriali: Località Pura 1/A, 33029 Lauco (UD)
- P. IVA: 02854930308

Vedi `SOURCES.md` per la provenienza delle informazioni pubbliche usate nei contenuti.
