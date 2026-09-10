import Foundation

enum AppConfig {
    /// URL de production du jeu.
    static let productionURL = URL(string: "https://6etapes.foutech-solutions.fr/")!

    /// URL effectivement chargée. En Debug, la variable d'environnement `SIXETAPES_URL`
    /// (schéma Xcode « SixEtapes (local) ») permet de viser un serveur local, ex. le
    /// serveur PHP intégré : `php -S 127.0.0.1:8765 -t "/Applications/MAMP/htdocs/6 grande"`.
    static let gameURL: URL = {
        #if DEBUG
        if let raw = ProcessInfo.processInfo.environment["SIXETAPES_URL"],
           let url = URL(string: raw), url.host != nil {
            return url
        }
        #endif
        return productionURL
    }()

    /// Hôtes ouverts dans l'app ; tout autre lien part dans Safari.
    static let internalHosts: Set<String> = {
        var hosts: Set<String> = [productionURL.host!.lowercased()]
        if let host = gameURL.host?.lowercased() { hosts.insert(host) }
        return hosts
    }()

    /// Suffixe d'user-agent détecté par `assets/app.js` (classe `in-app`, pont haptique).
    static let userAgentSuffix = "SixEtapesApp/1.0"

    /// Couleur de fond du jeu (`--bg` dans app.css) pour éviter un flash blanc au lancement.
    static let backgroundRGB: (red: Double, green: Double, blue: Double) = (0.933, 0.953, 0.984)
}
