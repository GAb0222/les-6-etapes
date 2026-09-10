import SwiftUI

struct ContentView: View {
    @StateObject private var model = GameWebModel()

    private var background: Color {
        Color(red: AppConfig.backgroundRGB.red, green: AppConfig.backgroundRGB.green, blue: AppConfig.backgroundRGB.blue)
    }

    var body: some View {
        ZStack(alignment: .top) {
            background.ignoresSafeArea()

            // La page gère elle-même les safe areas (viewport-fit=cover + env(safe-area-inset-*)).
            GameWebView(model: model)
                .ignoresSafeArea()
                .opacity(model.loadFailed ? 0 : 1)

            if model.isLoading && !model.loadFailed {
                ProgressView(value: min(max(model.progress, 0.05), 1))
                    .progressViewStyle(.linear)
                    .tint(.accentColor)
                    .frame(height: 3)
                    .transition(.opacity)
            }

            if model.loadFailed {
                OfflineView(httpStatus: model.httpStatus, retry: model.reload)
            }
        }
        .animation(.easeOut(duration: 0.2), value: model.isLoading)
        .animation(.easeOut(duration: 0.2), value: model.loadFailed)
        .preferredColorScheme(.light)
        // Universal links (QR de séance https://…/s/CODE) : ouverture directe dans le jeu.
        .onOpenURL { url in
            model.open(url)
        }
    }
}

/// Écran affiché quand le jeu ne peut pas être chargé (pas de réseau, serveur indisponible).
struct OfflineView: View {
    var httpStatus: Int? = nil
    let retry: () -> Void

    private var isServerError: Bool { httpStatus != nil }

    private var title: String {
        isServerError ? "Jeu momentanément indisponible" : "Connexion impossible"
    }

    private var message: String {
        if let httpStatus {
            return "Le serveur ne répond pas correctement (erreur \(httpStatus)).\nRéessaie dans quelques instants."
        }
        return "Le jeu a besoin d'Internet pour se charger.\nVérifie ta connexion puis réessaie."
    }

    var body: some View {
        VStack(spacing: 18) {
            Spacer()
            Image(systemName: isServerError ? "exclamationmark.icloud" : "wifi.slash")
                .font(.system(size: 52, weight: .semibold))
                .foregroundStyle(.secondary)
            Text(title)
                .font(.title2.weight(.bold))
                .multilineTextAlignment(.center)
            Text(message)
                .font(.body)
                .foregroundStyle(.secondary)
                .multilineTextAlignment(.center)
                .padding(.horizontal, 32)
            Button(action: retry) {
                Text("Réessayer")
                    .font(.headline)
                    .frame(maxWidth: 260)
                    .padding(.vertical, 14)
            }
            .buttonStyle(.borderedProminent)
            .padding(.top, 6)
            Spacer()
            Spacer()
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .background(Color(red: AppConfig.backgroundRGB.red, green: AppConfig.backgroundRGB.green, blue: AppConfig.backgroundRGB.blue))
    }
}

#Preview {
    ContentView()
}
