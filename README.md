# mikhmonv3-safelinkhub

Mikhmon V3 adapte pour SafelinkHub et les containers MikroTik RouterOS.

## Images DockerHub

Depot DockerHub: `latif225/mikhmonv3-safelinkhub`

- `arm32` et `armv7`: MikroTik ARMv7, dont hAP ax lite.
- `arm64`: equipements MikroTik ARM64, dont hAP ax2.

Exemple RouterOS:

```routeros
/container add name=mikhmon interface=veth-mikhmon root-dir=mikhmon-root remote-image=latif225/mikhmonv3-safelinkhub:arm32 start-on-boot=yes
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
