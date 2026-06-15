# Mikhmon v3 SafeLink Hub pour MikroTik Hotspot

Image Docker preparee pour administrer un hotspot MikroTik avec Mikhmon: profils, vouchers, vendeurs, revenus, IP Binding temporise et interface plus lisible sur mobile.

<p align="center">
  <img src="https://raw.githubusercontent.com/iamabdoulatif/mikhmon-sf-v1/main/dockerhub/assets/admin-login-readable.png" alt="Mikhmon SafeLink Hub - page de connexion admin, manager et vendeur" width="720">
</p>

## Apercus lisibles

Les captures ci-dessous sont recadrees pour rester lisibles directement dans Docker Hub.

### Connexion admin, manager et vendeur

<p align="center">
  <img src="https://raw.githubusercontent.com/iamabdoulatif/mikhmon-sf-v1/main/dockerhub/assets/admin-login-readable.png" alt="Page de connexion Mikhmon avec les onglets Admin, Manager et Vendor" width="720">
</p>

### Connexion vendeur

<p align="center">
  <img src="https://raw.githubusercontent.com/iamabdoulatif/mikhmon-sf-v1/main/dockerhub/assets/vendor-login-readable.png" alt="Page Vendor Login Mikhmon recadree pour Docker Hub" width="720">
</p>

### IP Binding mobile

<p align="center">
  <img src="https://raw.githubusercontent.com/iamabdoulatif/mikhmon-sf-v1/main/dockerhub/assets/mobile-ip-binding-readable.png" alt="Formulaire IP Binding Mikhmon lisible sur mobile" width="520">
</p>

## Tag disponible

```text
latif225/mikhmon-sf-v1:latest
```

Le tag `latest` est un manifeste multi-architecture aplati avec `skopeo`.
DockerHub choisit automatiquement l'image qui correspond au routeur ou au serveur:

- `linux/amd64` pour les serveurs classiques.
- `linux/arm64` pour les routeurs ARM64 comme hAP ax2 / hAP ax3.
- `linux/s390x`.
- `linux/arm/v6`.
- `linux/arm/v7` pour les routeurs ARM comme hAP ax lite.

## Fonctions incluses

- Creation et gestion des profils MikroTik Hotspot.
- Generation de vouchers et tickets clients.
- Suivi des ventes par profil, manager, vendeur et session.
- Revenus journaliers et mensuels visibles dans le dashboard.
- IP Binding avec duree liee au profil choisi.
- Expiration automatique via scheduler RouterOS.
- Nettoyage possible des sessions actives, queues, ARP, DHCP et IP bindings.
- Interface recentree et plus lisible sur mobile.
- Images RouterOS aplaties avec `skopeo` pour reduire les couches.

## Utilisation Docker

```bash
docker run --rm -p 8080:80 latif225/mikhmon-sf-v1:latest
```

Ouvrir ensuite:

```text
http://localhost:8080
```

## Utilisation MikroTik RouterOS containers

Sur RouterOS 7, utiliser le tag unique `latest`. Le routeur selectionne automatiquement l'architecture compatible depuis le manifeste DockerHub.

Exemple reseau RouterOS:

```routeros
/interface/bridge/add name=DOCKER
/interface/veth/add name=MIKHMON address=11.11.11.11/28 gateway=11.11.11.1
/interface/bridge/port/add bridge=DOCKER interface=MIKHMON
/ip/address/add address=11.11.11.1/28 interface=DOCKER
```

Exemple container:

```routeros
/container/config/set registry-url=https://registry-1.docker.io tmpdir=/tmp
/container/add remote-image=latif225/mikhmon-sf-v1:latest check-certificate=no root-dir=/flash/mikhmon-root name=mikhmon interface=MIKHMON logging=yes start-on-boot=yes
/container/start [find name="mikhmon"]
```

Acces:

```text
http://11.11.11.11
```

## Connexion API MikroTik

Dans Mikhmon, ajouter une session avec l'adresse du routeur, l'utilisateur RouterOS, le mot de passe et le port API. Le port API non SSL est generalement `8728`.

Verifier que l'API RouterOS est active:

```routeros
/ip/service/enable api
/ip/service/print where name=api
```

Pour un deploiement public, utiliser un utilisateur RouterOS dedie, changer les mots de passe par defaut et limiter l'acces API par firewall.
