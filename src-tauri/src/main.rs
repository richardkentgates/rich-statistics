use tauri_plugin_updater::UpdaterExt;

#[tauri::command]
async fn check_for_update(app: tauri::AppHandle) -> Result<(), String> {
    let updater = app.updater().map_err(|e| e.to_string())?;
    let update = updater.check().await.map_err(|e| e.to_string())?;
    if let Some(update) = update {
        let version = update.version.clone();
        let downloaded = update.download(|_, _| {}, || {}).await.map_err(|e| e.to_string())?;
        downloaded.install().map_err(|e| e.to_string())?;
        drop(app); // let install finish
        let _ = version; // suppress unused warning
    }
    Ok(())
}

fn main() {
    tauri::Builder::default()
        .plugin(tauri_plugin_updater::Builder::new().build())
        .plugin(tauri_plugin_dialog::init())
        .plugin(tauri_plugin_shell::init())
        .invoke_handler(tauri::generate_handler![check_for_update])
        .run(tauri::generate_context!())
        .expect("error while running tauri application");
}
