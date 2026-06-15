# mikhmon-sf-v1

Mikhmon V3 adapte pour SafelinkHub et les containers MikroTik RouterOS.

## Images DockerHub

Depot DockerHub: `latif225/mikhmon-sf-v1`

Le tag `latest` est un manifeste multi-architecture aplati avec `skopeo`.
DockerHub selectionne automatiquement l'image compatible avec le routeur ou le serveur.

Exemple RouterOS:

```routeros
/container add name=mikhmon interface=veth-mikhmon root-dir=mikhmon-root remote-image=latif225/mikhmon-sf-v1:latest start-on-boot=yes
/container start mikhmon
```

## Corrections incluses

- creation de profils/vouchers compatible avec RouterOS meme lorsque les scripts contiennent des signes `=`;
- expiration automatique des tickets selon la duree configuree;
- revenus journaliers et mensuels visibles dans le dashboard Mikhmon;
- IP Binding avec duree liee au profil choisi;
- interface IP Binding responsive mobile.

## Publication automatique

Le workflow GitHub Actions `.github/workflows/dockerhub.yml` reconstruit et pousse les images aplaties vers DockerHub a chaque `push` sur la branche `main`.

Secrets requis dans le depot GitHub:

- `DOCKERHUB_USERNAME`
- `DOCKERHUB_TOKEN`
