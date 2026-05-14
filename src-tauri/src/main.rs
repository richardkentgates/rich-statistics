fn main() {
    // Force software rendering on Raspberry Pi — WebKitGTK GPU compositing
    // is broken on VC4/V3D drivers, causing blank windows until a resize
    // forces a software repaint.
    if std::fs::read_to_string("/proc/device-tree/model")
        .map(|m| m.to_lowercase().contains("raspberry"))
        .unwrap_or(false)
    {
        std::env::set_var("WEBKIT_DISABLE_COMPOSITING_MODE", "1");
        std::env::set_var("LIBGL_ALWAYS_SOFTWARE", "1");
    }

    tauri::Builder::default()
        .run(tauri::generate_context!())
        .expect("error while running tauri application");
}
