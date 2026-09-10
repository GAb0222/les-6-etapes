import SwiftUI
import WebKit

/// État partagé entre la vue SwiftUI et la WKWebView.
@MainActor
final class GameWebModel: ObservableObject {
    @Published var isLoading = true
    @Published var loadFailed = false
    @Published var progress: Double = 0
    /// Code HTTP reçu quand le serveur répond mais en erreur (ex. 503), nil sinon.
    @Published var httpStatus: Int?

    weak var webView: WKWebView?

    func reload() {
        loadFailed = false
        httpStatus = nil
        isLoading = true
        if let webView, webView.url != nil {
            webView.reload()
        } else {
            webView?.load(URLRequest(url: AppConfig.gameURL))
        }
    }

    /// URL reçue par universal link (QR `/s/CODE`, lien de séance) : chargée dans la
    /// WebView si elle vise un hôte du jeu, sinon ignorée. Si la WebView n'existe pas
    /// encore (lancement à froid), l'URL est gardée pour le premier chargement.
    var pendingURL: URL?

    func open(_ url: URL) {
        guard let host = url.host?.lowercased(), AppConfig.internalHosts.contains(host) else { return }
        loadFailed = false
        httpStatus = nil
        if let webView {
            isLoading = true
            webView.load(URLRequest(url: url))
        } else {
            pendingURL = url
        }
    }
}

/// WKWebView qui affiche le jeu, avec pont haptique, gestion hors-ligne,
/// liens externes vers Safari et support des `alert()` / `confirm()` JavaScript.
struct GameWebView: UIViewRepresentable {
    @ObservedObject var model: GameWebModel

    private static let hapticHandlerName = "haptic"

    func makeCoordinator() -> Coordinator {
        Coordinator(model: model)
    }

    func makeUIView(context: Context) -> WKWebView {
        let configuration = WKWebViewConfiguration()
        configuration.allowsInlineMediaPlayback = true
        // Conserve l'UA Safari iOS standard et ajoute notre marqueur (lu par app.js).
        configuration.applicationNameForUserAgent = "Mobile/15E148 \(AppConfig.userAgentSuffix)"
        configuration.userContentController.add(context.coordinator, name: Self.hapticHandlerName)

        let webView = WKWebView(frame: .zero, configuration: configuration)
        webView.navigationDelegate = context.coordinator
        webView.uiDelegate = context.coordinator
        webView.allowsBackForwardNavigationGestures = false
        webView.isOpaque = false
        webView.backgroundColor = UIColor(
            red: AppConfig.backgroundRGB.red,
            green: AppConfig.backgroundRGB.green,
            blue: AppConfig.backgroundRGB.blue,
            alpha: 1
        )
        webView.scrollView.backgroundColor = webView.backgroundColor
        // La page pose ses propres marges de sécurité : on ne laisse pas UIKit en rajouter.
        webView.scrollView.contentInsetAdjustmentBehavior = .never
        webView.scrollView.bounces = false

        context.coordinator.observeProgress(of: webView)
        model.webView = webView
        let initialURL = model.pendingURL ?? AppConfig.gameURL
        model.pendingURL = nil
        webView.load(URLRequest(url: initialURL))
        return webView
    }

    func updateUIView(_ uiView: WKWebView, context: Context) {}

    static func dismantleUIView(_ uiView: WKWebView, coordinator: Coordinator) {
        uiView.configuration.userContentController.removeScriptMessageHandler(forName: hapticHandlerName)
        coordinator.stopObserving()
    }

    @MainActor
    final class Coordinator: NSObject, WKNavigationDelegate, WKUIDelegate, WKScriptMessageHandler {
        private let model: GameWebModel
        private var progressObservation: NSKeyValueObservation?
        private let notificationFeedback = UINotificationFeedbackGenerator()
        private let impactFeedback = UIImpactFeedbackGenerator(style: .light)

        init(model: GameWebModel) {
            self.model = model
        }

        func observeProgress(of webView: WKWebView) {
            progressObservation = webView.observe(\.estimatedProgress, options: [.new]) { [weak self] webView, _ in
                let value = webView.estimatedProgress
                Task { @MainActor [weak self] in
                    self?.model.progress = value
                }
            }
        }

        func stopObserving() {
            progressObservation = nil
        }

        // MARK: - Pont haptique
        // JS : window.webkit.messageHandlers.haptic.postMessage("success" | "error" | "light")

        func userContentController(_ userContentController: WKUserContentController, didReceive message: WKScriptMessage) {
            guard message.name == GameWebView.hapticHandlerName, let kind = message.body as? String else { return }
            switch kind {
            case "success":
                notificationFeedback.notificationOccurred(.success)
            case "error":
                notificationFeedback.notificationOccurred(.error)
            default:
                impactFeedback.impactOccurred()
            }
        }

        // MARK: - Navigation

        func webView(_ webView: WKWebView, didStartProvisionalNavigation navigation: WKNavigation!) {
            model.isLoading = true
        }

        func webView(_ webView: WKWebView, didFinish navigation: WKNavigation!) {
            model.isLoading = false
            model.loadFailed = false
        }

        func webView(_ webView: WKWebView, didFail navigation: WKNavigation!, withError error: Error) {
            handle(error)
        }

        func webView(_ webView: WKWebView, didFailProvisionalNavigation navigation: WKNavigation!, withError error: Error) {
            handle(error)
        }

        func webViewWebContentProcessDidTerminate(_ webView: WKWebView) {
            // Le processus web a été tué par iOS (mémoire) : on recharge, la partie est
            // sauvegardée côté page (localStorage) et reprend toute seule.
            webView.reload()
        }

        private func handle(_ error: Error) {
            let nsError = error as NSError
            // -999 : navigation annulée par une nouvelle requête, ce n'est pas un échec.
            if nsError.domain == NSURLErrorDomain && nsError.code == NSURLErrorCancelled { return }
            // Réponse HTTP refusée ci-dessous (erreur 4xx/5xx) : l'état est déjà posé,
            // on garde le code HTTP pour l'afficher.
            if model.loadFailed && model.httpStatus != nil { return }
            model.isLoading = false
            model.loadFailed = true
        }

        /// Une page d'erreur du serveur (503 « Service Unavailable », 500, 404…) est une
        /// réponse HTTP valide pour WebKit : sans ce filtre elle s'afficherait telle quelle.
        func webView(
            _ webView: WKWebView,
            decidePolicyFor navigationResponse: WKNavigationResponse,
            decisionHandler: @escaping (WKNavigationResponsePolicy) -> Void
        ) {
            if navigationResponse.isForMainFrame,
               let http = navigationResponse.response as? HTTPURLResponse,
               http.statusCode >= 400 {
                model.httpStatus = http.statusCode
                model.isLoading = false
                model.loadFailed = true
                decisionHandler(.cancel)
                return
            }
            decisionHandler(.allow)
        }

        func webView(
            _ webView: WKWebView,
            decidePolicyFor navigationAction: WKNavigationAction,
            decisionHandler: @escaping (WKNavigationActionPolicy) -> Void
        ) {
            guard let url = navigationAction.request.url else {
                decisionHandler(.allow)
                return
            }

            if let scheme = url.scheme?.lowercased(), scheme == "mailto" || scheme == "tel" {
                UIApplication.shared.open(url)
                decisionHandler(.cancel)
                return
            }

            if navigationAction.navigationType == .linkActivated,
               let host = url.host?.lowercased(),
               !AppConfig.internalHosts.contains(host) {
                UIApplication.shared.open(url)
                decisionHandler(.cancel)
                return
            }

            decisionHandler(.allow)
        }

        /// Liens `target="_blank"` : même WebView si interne, Safari sinon.
        func webView(
            _ webView: WKWebView,
            createWebViewWith configuration: WKWebViewConfiguration,
            for navigationAction: WKNavigationAction,
            windowFeatures: WKWindowFeatures
        ) -> WKWebView? {
            guard let url = navigationAction.request.url else { return nil }
            if let host = url.host?.lowercased(), AppConfig.internalHosts.contains(host) {
                webView.load(URLRequest(url: url))
            } else {
                UIApplication.shared.open(url)
            }
            return nil
        }

        // MARK: - alert() / confirm() JavaScript
        // Sans ces méthodes, WKWebView ignore silencieusement les dialogues (et confirm() renvoie false).

        func webView(
            _ webView: WKWebView,
            runJavaScriptAlertPanelWithMessage message: String,
            initiatedByFrame frame: WKFrameInfo,
            completionHandler: @escaping () -> Void
        ) {
            presentAlert(
                message: message,
                actions: [("OK", .default, { completionHandler() })],
                fallback: completionHandler
            )
        }

        func webView(
            _ webView: WKWebView,
            runJavaScriptConfirmPanelWithMessage message: String,
            initiatedByFrame frame: WKFrameInfo,
            completionHandler: @escaping (Bool) -> Void
        ) {
            presentAlert(
                message: message,
                actions: [
                    ("Annuler", .cancel, { completionHandler(false) }),
                    ("OK", .default, { completionHandler(true) }),
                ],
                fallback: { completionHandler(false) }
            )
        }

        private func presentAlert(
            message: String,
            actions: [(title: String, style: UIAlertAction.Style, handler: () -> Void)],
            fallback: () -> Void
        ) {
            guard let presenter = Self.topViewController() else {
                fallback()
                return
            }
            let alert = UIAlertController(title: nil, message: message, preferredStyle: .alert)
            for action in actions {
                alert.addAction(UIAlertAction(title: action.title, style: action.style) { _ in action.handler() })
            }
            presenter.present(alert, animated: true)
        }

        private static func topViewController() -> UIViewController? {
            let windows = UIApplication.shared.connectedScenes
                .compactMap { $0 as? UIWindowScene }
                .flatMap(\.windows)
            guard var top = (windows.first(where: \.isKeyWindow) ?? windows.first)?.rootViewController else {
                return nil
            }
            while let presented = top.presentedViewController {
                top = presented
            }
            return top
        }
    }
}
