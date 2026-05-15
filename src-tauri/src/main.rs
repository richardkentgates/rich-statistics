use std::process::{Command, Stdio};
use std::sync::Mutex;
use tauri::Manager;

struct OllamaProcess(Mutex<Option<std::process::Child>>);

#[tauri::command]
fn check_ollama() -> Result<serde_json::Value, String> {
    let installed = find_ollama_binary().is_ok();
    let running = is_ollama_running();
    let models = if running {
        list_ollama_models().ok().unwrap_or_default()
    } else {
        Vec::new()
    };

    Ok(serde_json::json!({
        "installed": installed,
        "running": running,
        "models": models,
    }))
}

#[tauri::command]
fn start_ollama(app: tauri::AppHandle) -> Result<(), String> {
    if is_ollama_running() {
        return Ok(());
    }

    let binary = find_ollama_binary()?;

    let child = Command::new(&binary)
        .arg("serve")
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .spawn()
        .map_err(|e| format!("Failed to start Ollama: {}", e))?;

    app.state::<OllamaProcess>().0.lock().unwrap().replace(child);

    // Wait up to 30s for it to be ready
    for _ in 0..60 {
        std::thread::sleep(std::time::Duration::from_millis(500));
        if is_ollama_running() {
            return Ok(());
        }
    }

    Err("Ollama started but did not respond after 30 seconds.".to_string())
}

#[tauri::command]
fn stop_ollama(app: tauri::AppHandle) -> Result<(), String> {
    let mut guard = app.state::<OllamaProcess>().0.lock().unwrap();
    if let Some(mut child) = guard.take() {
        child.kill().map_err(|e| format!("Failed to stop Ollama: {}", e))?;
    }
    Ok(())
}

#[tauri::command]
fn list_ollama_models() -> Result<Vec<String>, String> {
    let output = Command::new("ollama")
        .arg("list")
        .output()
        .map_err(|e| format!("Failed to list models: {}", e))?;

    if !output.status.success() {
        let stderr = String::from_utf8_lossy(&output.stderr);
        return Err(format!("ollama list failed: {}", stderr));
    }

    let stdout = String::from_utf8_lossy(&output.stdout);
    let mut models = Vec::new();
    for (i, line) in stdout.lines().enumerate() {
        if i == 0 {
            continue; // Skip header
        }
        let name = line.split_whitespace().next().unwrap_or("");
        if !name.is_empty() {
            models.push(name.trim_end_matches(":latest").to_string());
        }
    }
    Ok(models)
}

#[tauri::command]
fn pull_ollama_model(model: String) -> Result<(), String> {
    let status = Command::new("ollama")
        .args(["pull", &model])
        .stdout(Stdio::inherit())
        .stderr(Stdio::inherit())
        .status()
        .map_err(|e| format!("Failed to start download: {}", e))?;

    if status.success() {
        Ok(())
    } else {
        Err(format!("Failed to download model '{}'", model))
    }
}

fn find_ollama_binary() -> Result<String, String> {
    let common = [
        "/usr/local/bin/ollama",
        "/usr/bin/ollama",
        "/opt/homebrew/bin/ollama",
        "C:\\Program Files\\Ollama\\ollama.exe",
        "C:\\Program Files (x86)\\Ollama\\ollama.exe",
    ];

    for path in &common {
        if std::path::Path::new(path).exists() {
            return Ok(path.to_string());
        }
    }

    // Try PATH lookup
    if cfg!(target_os = "windows") {
        if let Ok(output) = Command::new("where").arg("ollama").output() {
            if output.status.success() {
                let path = String::from_utf8_lossy(&output.stdout).trim().to_string();
                if !path.is_empty() {
                    return Ok(path);
                }
            }
        }
    } else {
        if let Ok(output) = Command::new("which").arg("ollama").output() {
            if output.status.success() {
                let path = String::from_utf8_lossy(&output.stdout).trim().to_string();
                if !path.is_empty() {
                    return Ok(path);
                }
            }
        }
    }

    Err("Ollama not found. Install from https://ollama.com".to_string())
}

fn is_ollama_running() -> bool {
    std::net::TcpStream::connect_timeout(
        &"127.0.0.1:11434".parse().unwrap(),
        std::time::Duration::from_millis(300),
    )
    .is_ok()
}

fn main() {
    if let Ok(model) = std::fs::read_to_string("/proc/device-tree/model") {
        if model.to_lowercase().contains("raspberry") {
            std::env::set_var("WEBKIT_DISABLE_COMPOSITING_MODE", "1");
            std::env::set_var("LIBGL_ALWAYS_SOFTWARE", "1");
        }
    }

    tauri::Builder::default()
        .manage(OllamaProcess(Mutex::new(None)))
        .invoke_handler(tauri::generate_handler![
            check_ollama,
            start_ollama,
            stop_ollama,
            list_ollama_models,
            pull_ollama_model,
        ])
        .on_window_event(|window, event| {
            if let tauri::WindowEvent::CloseRequested { .. } = event {
                // Kill ollama if we started it
                if let Some(state) = window.try_state::<OllamaProcess>() {
                    if let Ok(mut guard) = state.0.lock() {
                        if let Some(mut child) = guard.take() {
                            let _ = child.kill();
                        }
                    }
                }
            }
        })
        .run(tauri::generate_context!())
        .expect("error while running tauri application");
}
