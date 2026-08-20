use std::sync::Mutex;
use tauri::{Emitter, Manager, WebviewUrl, WebviewWindowBuilder};
use tauri_plugin_shell::process::{CommandChild, CommandEvent};
use tauri_plugin_shell::ShellExt;

// Holds the running llama-server child process so it can be killed when the
// app actually quits (tray "Quit"), not just when the window is closed --
// closing the window now hides it instead (see the CloseRequested handler
// in run()), so the server stays alive in the background for chat polling.
struct GemmaServer(Mutex<Option<CommandChild>>);

// A PDF handed to us via "Open with" (either at cold start, via argv, or
// from a second launch attempt forwarded through the single-instance
// plugin) while the frontend wasn't ready/loaded yet to receive it.
// get_pending_pdf_file() lets the frontend pull it once on boot.
#[derive(Clone, serde::Serialize)]
struct PendingPdf {
    name: String,
    // base64-encoded raw PDF bytes -- far cheaper to move across the
    // Tauri IPC/event bus than a JSON array of individual byte numbers,
    // and trivially decoded on the JS side with atob().
    bytes_b64: String,
}
struct PendingPdfState(Mutex<Option<PendingPdf>>);

#[tauri::command]
fn get_pending_pdf_file(state: tauri::State<PendingPdfState>) -> Option<PendingPdf> {
    state.0.lock().unwrap().take()
}

// Reads a PDF straight off disk into a PendingPdf. Done here in Rust
// (rather than handing the raw path to the frontend and having it read
// the file itself) so we never need to grant the webview's fs plugin a
// broad "read anywhere on disk" scope just to open whatever file Windows
// launched us with.
fn read_pdf_as_pending(path: &str) -> Option<PendingPdf> {
    use base64::{engine::general_purpose::STANDARD, Engine as _};
    let bytes = std::fs::read(path).ok()?;
    let name = std::path::Path::new(path)
        .file_name()
        .map(|n| n.to_string_lossy().to_string())
        .unwrap_or_else(|| "document.pdf".to_string());
    Some(PendingPdf {
        name,
        bytes_b64: STANDARD.encode(bytes),
    })
}

// Finds a .pdf path among a process's CLI args (skipping argv[0], the exe
// path itself) -- used both for our own cold-start args and for argv
// forwarded by the single-instance plugin when a second launch (e.g. from
// "Open with") gets folded into this already-running instance.
fn find_pdf_arg<S: AsRef<str>>(args: &[S]) -> Option<PendingPdf> {
    args.iter()
        .skip(1)
        .map(|a| a.as_ref())
        .find(|a| a.to_lowercase().ends_with(".pdf"))
        .and_then(read_pdf_as_pending)
}

// Makes window.open() in the site's code open links in the user's real
// default browser via Tauri's opener plugin, instead of a WebView2 popup.
// Injected on every page load in the main window (see .initialization_script
// below). Only activates when window.__TAURI__ is present.

// Windows-only fix for a known WebView2/Chromium issue: after the system
// sleeps and wakes, the GPU device is sometimes reset (especially on laptops
// with hybrid/switchable graphics). Chromium detects the lost GPU context,
// crashes the renderer, and silently reloads the page to recover - which
// wipes all in-memory frontend state since nothing was actually navigating
// away on purpose. Forcing software rendering (--disable-gpu-compositing)
// avoids that GPU-context loss entirely, and the background-throttling flags
// stop Windows from suspending/deprioritizing the renderer while the window
// is minimized or unfocused (another common trigger for the same reset).
// NOTE: wry itself passes --disable-features=msWebOOUI,msPdfOOUI,msSmartScreenProtection
// by default; using additional_browser_args() overrides that, so we repeat
// those flags here alongside our own.
const WEBVIEW2_BROWSER_ARGS: &str = "--disable-features=msWebOOUI,msPdfOOUI,msSmartScreenProtection --disable-gpu-compositing --disable-background-timer-throttling --disable-backgrounding-occluded-windows --disable-renderer-backgrounding";

const DESKTOP_BRIDGE_JS: &str = r#"
(function () {
  // Disable the default browser/WebView2 right-click context menu (Back,
  // Refresh, Save As, Print, More tools, etc.) everywhere in the window.
  // This must run unconditionally, before the window.__TAURI__ check below,
  // otherwise it silently never registers if this script executes before
  // Tauri's own bridge has finished setting window.__TAURI__.
  document.addEventListener("contextmenu", function (e) {
    e.preventDefault();
  }, true);

  if (!window.__TAURI__) return;

  var originalOpen = window.open.bind(window);
  window.open = function (url, target, features) {
    try {
      if (
        url &&
        window.__TAURI__.opener &&
        typeof window.__TAURI__.opener.openUrl === "function"
      ) {
        window.__TAURI__.opener.openUrl(url);
        return null;
      }
    } catch (err) {
      console.warn("Desktop bridge: openUrl failed, falling back", err);
    }
    return originalOpen(url, target, features);
  };

  // Safety net: if the renderer ever does get reloaded unexpectedly (e.g. an
  // edge case the WebView2 GPU/throttling flags in Rust don't catch), don't
  // let whatever the user was typing vanish. Autosave text-like form fields
  // to localStorage (which survives a page reload, unlike JS memory) and
  // restore them once the page comes back. Password fields are skipped.
  function bk(el) {
    var id = el.id || el.name;
    if (!id) return null;
    return "__desktop_autosave__:" + location.pathname + ":" + id;
  }
  function restore() {
    document.querySelectorAll("input, textarea, select").forEach(function (el) {
      if (el.type === "password") return;
      var key = bk(el);
      if (!key) return;
      try {
        var saved = localStorage.getItem(key);
        if (saved === null) return;
        if (el.type === "checkbox" || el.type === "radio") {
          el.checked = saved === "true";
        } else {
          el.value = saved;
        }
      } catch (err) {}
    });
  }
  var saveTimer = null;
  function scheduleSave(el) {
    if (el.type === "password") return;
    var key = bk(el);
    if (!key) return;
    clearTimeout(saveTimer);
    saveTimer = setTimeout(function () {
      try {
        var val = el.type === "checkbox" || el.type === "radio" ? String(el.checked) : el.value;
        localStorage.setItem(key, val);
      } catch (err) {}
    }, 250);
  }
  document.addEventListener("input", function (e) { scheduleSave(e.target); }, true);
  document.addEventListener("change", function (e) { scheduleSave(e.target); }, true);
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", restore);
  } else {
    restore();
  }

  // ---------------------------------------------------------------------
  // Fixes stale content after the site is updated on the server, and adds
  // limited offline support. Registers /sw.js (hosted on beuclub.site.je
  // itself) which checksum-checks every file against the server via its
  // ETag header: unchanged files are served instantly from a local cache,
  // changed files are re-downloaded and the cache is updated, and cached
  // files are used as a fallback when there's no network at all.
  // ---------------------------------------------------------------------
  if ("serviceWorker" in navigator) {
    navigator.serviceWorker.register("/sw.js", { scope: "/" }).then(function (reg) {
      // Pages loaded before the service worker finishes activating aren't
      // "controlled" by it yet. Reload once per app session so this run
      // benefits immediately instead of waiting for the next restart.
      if (!navigator.serviceWorker.controller) {
        var reloadedKey = "__sw_bootstrap_reloaded__";
        if (!sessionStorage.getItem(reloadedKey)) {
          sessionStorage.setItem(reloadedKey, "1");
          navigator.serviceWorker.ready.then(function () {
            location.reload();
          });
        }
      }
    }).catch(function (err) {
      console.warn("Desktop bridge: service worker registration failed", err);
    });
  }

  // ---------------------------------------------------------------------
  // "Open with beuclub" support (see tauri.conf.json's fileAssociations
  // and the single-instance/get_pending_pdf_file plumbing in lib.rs).
  // A PDF opened from Windows Explorer lands here as either:
  //   - an "open-pdf-file" event, if this instance was already running
  //     and Windows just forwarded the new launch's argv to it, or
  //   - whatever get_pending_pdf_file() returns, checked once on boot,
  //     for the case where THIS launch is the one carrying the PDF path.
  // Board (switchAppView/pdfLoadFile) may not be defined yet the instant
  // this script runs, so both paths funnel through a small ready-check
  // retry instead of assuming the rest of the shell has already loaded.
  // ---------------------------------------------------------------------
  function b64ToUint8Array(b64) {
    var bin = atob(b64);
    var arr = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
    return arr;
  }
  function openIncomingPdf(name, bytesB64) {
    var tries = 0;
    (function attempt() {
      var ready = typeof switchAppView === "function" && typeof pdfLoadFile === "function";
      if (!ready) {
        if (++tries > 100) return; // ~10s — give up rather than retry forever
        setTimeout(attempt, 100);
        return;
      }
      try {
        // Tell pdfreader.js's one-time boot (init(), triggered here via
        // the hashchange below, or already in flight via login's own
        // hideAuthScreen() -> pdfRestoreOnFirstView() call) not to
        // restore last session's tab(s) on top of this one. Without
        // this, pdfRestoreLast() -- which unconditionally makes each
        // restored tab active in turn -- can finish AFTER pdfLoadFile()
        // below (its own IndexedDB reads race pdfLoadFile's arrayBuffer
        // + hash + IndexedDB chain, and if last session had more than
        // one tab open, a later one in that loop can complete after
        // this PDF does), silently stealing the active tab back. The
        // person lands on Board looking at whatever they had open last
        // time, with the PDF they just double-clicked buried as an
        // inactive tab. See init()'s pdfRestoreLast() guard, which
        // already skips the same way for board.html?pdf=.
        window.__pendingIncomingPdf = true;
        switchAppView("board");
        var file = new File([b64ToUint8Array(bytesB64)], name || "document.pdf", { type: "application/pdf" });
        // switchAppView() only sets location.hash -- the Board pane isn't
        // actually shown (class="active", real clientWidth) until the
        // 'hashchange' listener it triggers gets around to running
        // activateCurrentView(), which is a separate, later task. Calling
        // pdfLoadFile() straight away used to race that: its fit-to-pane
        // zoom step reads the pane's clientWidth off two
        // requestAnimationFrame callbacks, which fire before that
        // hashchange task is processed, so it measured a still-hidden
        // (0-width) pane and locked the PDF at the minimum 0.4 zoom
        // instead of fitting it. Wait for the view to actually go active
        // first so that measurement sees the real, laid-out width.
        var waited = 0;
        (function waitForBoardActive() {
          var boardEl = document.getElementById('viewBoard');
          if ((boardEl && boardEl.classList.contains('active')) || ++waited > 100) {
            // ~10s cap above -- give up waiting and try anyway rather
            // than never opening the file at all.
            var result = pdfLoadFile(file);
            var clearFlag = function () { window.__pendingIncomingPdf = false; };
            if (result && typeof result.finally === "function") {
              result.finally(clearFlag);
            } else {
              clearFlag();
            }
            return;
          }
          setTimeout(waitForBoardActive, 100);
        })();
      } catch (err) {
        window.__pendingIncomingPdf = false;
        console.warn("Desktop bridge: failed to open incoming PDF", err);
      }
    })();
  }
  if (window.__TAURI__.event && typeof window.__TAURI__.event.listen === "function") {
    window.__TAURI__.event.listen("open-pdf-file", function (e) {
      var p = e.payload || {};
      openIncomingPdf(p.name, p.bytes_b64);
    });
  }
  if (window.__TAURI__.core && typeof window.__TAURI__.core.invoke === "function") {
    window.__TAURI__.core.invoke("get_pending_pdf_file").then(function (p) {
      if (p) openIncomingPdf(p.name, p.bytes_b64);
    }).catch(function () {});
  }

  // ---------------------------------------------------------------------
  // Restore-window-on-notification-click. The app now hides to the tray
  // instead of quitting when the window is closed (see run()'s
  // CloseRequested handler), so a click on a Windows toast notification
  // needs to bring the actual OS window back, not just focus the DOM --
  // window.focus() alone does not un-hide a hidden Tauri window. Exposed
  // as a global so notices.js / club.js's message-notification code can
  // call it from a Notification's onclick without needing to know
  // anything about Tauri.
  // ---------------------------------------------------------------------
  window.desktopFocusMainWindow = function () {
    try {
      var w = window.__TAURI__.window && window.__TAURI__.window.getCurrentWindow
        ? window.__TAURI__.window.getCurrentWindow()
        : null;
      if (w) {
        w.show();
        w.unminimize();
        w.setFocus();
        return;
      }
    } catch (err) {}
    window.focus();
  };
})();
"#;

// Appends a timestamped line to <app_log_dir>/gemma-debug.log so the
// llama-server startup sequence can be diagnosed in a release/installed
// build, where eprintln!/println! go nowhere visible (no console is
// attached to a windowed app). Falls back to eprintln! only if the log
// directory itself can't be created/written (e.g. permissions issue),
// so at least *something* surfaces in that edge case.
fn gemma_log(app: &tauri::AppHandle, msg: impl AsRef<str>) {
    let msg = msg.as_ref();
    let line = format!(
        "[{}] {}\n",
        chrono_like_timestamp(),
        msg
    );
    let logged = (|| -> std::io::Result<()> {
        let dir = app
            .path()
            .app_log_dir()
            .map_err(|e| std::io::Error::other(e.to_string()))?;
        std::fs::create_dir_all(&dir)?;
        let path = dir.join("gemma-debug.log");
        use std::io::Write;
        let mut f = std::fs::OpenOptions::new()
            .create(true)
            .append(true)
            .open(path)?;
        f.write_all(line.as_bytes())
    })();
    if logged.is_err() {
        eprintln!("{line}");
    }
    // Always also print, in case a console *is* attached (dev mode).
    print!("{line}");
}

// Minimal dependency-free "HH:MM:SS" wall-clock stamp so log lines are
// orderable without pulling in the `chrono` crate for one debug helper.
fn chrono_like_timestamp() -> String {
    use std::time::{SystemTime, UNIX_EPOCH};
    let secs = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|d| d.as_secs())
        .unwrap_or(0);
    let (h, m, s) = ((secs / 3600) % 24, (secs / 60) % 60, secs % 60);
    format!("{h:02}:{m:02}:{s:02}")
}

// Finds the bundled .gguf file under the app's resource dir (resources/models/*
// in tauri.conf.json gets copied to <resource_dir>/models/ at build time).
// Looking it up at runtime (instead of hardcoding a filename/path) means the
// quant file can be swapped without touching Rust code.
fn find_bundled_model(app: &tauri::AppHandle) -> Option<std::path::PathBuf> {
    let resource_dir = match app.path().resource_dir() {
        Ok(d) => d,
        Err(e) => {
            gemma_log(app, format!("resource_dir() failed: {e}"));
            return None;
        }
    };
    let models_dir = resource_dir.join("models");
    gemma_log(app, format!("looking for .gguf under: {}", models_dir.display()));
    let entries = match std::fs::read_dir(&models_dir) {
        Ok(e) => e,
        Err(e) => {
            gemma_log(app, format!("read_dir({}) failed: {e}", models_dir.display()));
            return None;
        }
    };
    let found = entries
        .filter_map(|e| e.ok())
        .map(|e| e.path())
        .find(|p| p.extension().is_some_and(|ext| ext == "gguf"));
    match &found {
        Some(p) => gemma_log(app, format!("found model: {}", p.display())),
        None => gemma_log(app, "no .gguf file found in models dir"),
    }
    found
}

// Starts llama-server as a Tauri sidecar and keeps it alive for the whole
// app session. Tuning notes for "loads fast, runs smoothly" on a 1B QAT
// GGUF model on ordinary consumer hardware:
//   -c 8192      small context = a tiny KV-cache allocation at startup,
//                which is most of what makes a fresh launch feel slow.
//                The original 131072 context was allocating far more than
//                a chat UI needs and was the single biggest startup cost.
//   -ngl 99      offload every layer to GPU when one is present; llama.cpp
//                clamps this to the actual layer count and falls back to
//                CPU automatically when there's no compatible GPU.
//   -fa on       flash attention - faster + lighter KV-cache on supported
//                hardware. Newer llama-server builds require an explicit
//                value (on/off/auto) instead of treating -fa as a bare
//                switch; passing it bare causes the next arg to be
//                swallowed as -fa's value and llama-server exits with
//                "unknown value for --flash-attn".
//   --mlock      pins the model in RAM so the OS can't page it back out
//                mid-generation (avoids stutter after the window loses focus).
// llama-server also runs its own graph warm-up pass before it reports
// healthy, so the *first* real chat request is already fast - starting the
// server here (at app launch) instead of on first chat-open means that
// warm-up finishes in the background while the user is still looking at
// the rest of the app.
fn spawn_gemma_server(app: &tauri::AppHandle) {
    gemma_log(app, "spawn_gemma_server: starting");

    let Some(model_path) = find_bundled_model(app) else {
        gemma_log(app, "no .gguf model found under resources/models, skipping sidecar start");
        return;
    };
    let Ok(model_path) = model_path.into_os_string().into_string() else {
        gemma_log(app, "model path is not valid UTF-8, skipping sidecar start");
        return;
    };

    let sidecar = match app.shell().sidecar("llama-server") {
        Ok(cmd) => cmd,
        Err(e) => {
            gemma_log(app, format!("failed to resolve llama-server sidecar: {e}"));
            return;
        }
    };

    let args = [
        "-m", model_path.as_str(),
        "-c", "8192",
        "-ngl", "99",
        "-fa", "on",
        "--mlock",
        "--host", "127.0.0.1",
        "--port", "8080",
    ];
    gemma_log(app, format!("spawning llama-server with args: {args:?}"));

    let spawn_result = sidecar.args(args).spawn();

    let (mut rx, child) = match spawn_result {
        Ok(pair) => pair,
        Err(e) => {
            // This is the error you get when the Tauri capability/ACL
            // scope rejects the args (e.g. an arg not whitelisted in
            // capabilities/default.json's shell:allow-execute), when the
            // sidecar binary is missing/misnamed, or when the OS refuses
            // to launch a corrupt/incompatible executable.
            gemma_log(app, format!("failed to spawn llama-server: {e}"));
            return;
        }
    };

    gemma_log(app, "llama-server process spawned, waiting for readiness");
    app.state::<GemmaServer>().0.lock().unwrap().replace(child);

    // Drain the sidecar's stdout/stderr so llama.cpp's own logs show up in
    // the debug log; also lets us know when it's actually ready instead of
    // guessing with a fixed delay.
    let app_for_task = app.clone();
    tauri::async_runtime::spawn(async move {
        while let Some(event) = rx.recv().await {
            match event {
                CommandEvent::Stdout(line) | CommandEvent::Stderr(line) => {
                    let line = String::from_utf8_lossy(&line).trim_end().to_string();
                    if line.is_empty() {
                        continue;
                    }
                    if line.contains("server is listening") || line.contains("all slots are idle")
                    {
                        gemma_log(&app_for_task, "llama-server ready on http://127.0.0.1:8080");
                    } else {
                        gemma_log(&app_for_task, format!("llama-server: {line}"));
                    }
                }
                CommandEvent::Error(err) => {
                    gemma_log(&app_for_task, format!("sidecar error: {err}"));
                }
                CommandEvent::Terminated(status) => {
                    gemma_log(&app_for_task, format!("llama-server exited: {status:?}"));
                }
                _ => {}
            }
        }
    });
}

// Shows and focuses the main window -- shared by the tray's "Open beuclub"
// menu item and a left-click on the tray icon itself.
fn show_main_window(app: &tauri::AppHandle) {
    if let Some(w) = app.get_webview_window("main") {
        let _ = w.show();
        let _ = w.unminimize();
        let _ = w.set_focus();
    }
}

// Kills the llama-server sidecar (if running) and actually terminates the
// process -- used only by the tray's "Quit" item, since closing the window
// itself now just hides it (see run()'s CloseRequested handler).
fn quit_app(app: &tauri::AppHandle) {
    if let Some(child) = app.state::<GemmaServer>().0.lock().unwrap().take() {
        let _ = child.kill();
    }
    app.exit(0);
}

#[cfg_attr(mobile, tauri::mobile_entry_point)]
pub fn run() {
    let builder = tauri::Builder::default();

    // Must be registered before any other plugin/window is created: on a
    // second launch (e.g. double-clicking a PDF via "Open with" while
    // beuclub is already running), this intercepts that second process
    // entirely -- it never gets its own window -- and instead hands the
    // running instance's own app handle + the second launch's argv to the
    // callback below, so we can forward the PDF path and refocus the
    // existing window instead of spawning a whole second app (and a
    // second llama-server) alongside it.
    #[cfg(desktop)]
    let builder = builder.plugin(tauri_plugin_single_instance::init(|app, argv, _cwd| {
        show_main_window(app);
        if let Some(pending) = find_pdf_arg(&argv) {
            let _ = app.emit("open-pdf-file", pending);
        }
    }));

    builder
        .plugin(tauri_plugin_opener::init())
        .plugin(tauri_plugin_shell::init())
        .manage(GemmaServer(Mutex::new(None)))
        .manage(PendingPdfState(Mutex::new(find_pdf_arg(
            &std::env::args().collect::<Vec<_>>(),
        ))))
        .invoke_handler(tauri::generate_handler![get_pending_pdf_file])
        .setup(|app| {
            // Load the live site directly (instead of a bundled local copy
            // calling the API cross-origin). This makes every request
            // same-origin/first-party, exactly like a normal browser tab,
            // which avoids the cross-site cookie/CORS restrictions that
            // block API calls coming from a separate app origin.
            let window = WebviewWindowBuilder::new(
                app,
                "main",
                WebviewUrl::External("https://beuclub.site.je/".parse().unwrap()),
            )
            .title("beuclub")
            .inner_size(1200.0, 800.0)
            .min_inner_size(760.0, 560.0)
            .resizable(true)
            .center()
            .additional_browser_args(WEBVIEW2_BROWSER_ARGS)
            .initialization_script(DESKTOP_BRIDGE_JS)
            .build()?;

            // Kick off the model server in the background right away, in
            // parallel with the window painting, instead of waiting for the
            // user to open a chat feature first.
            spawn_gemma_server(&app.handle().clone());

            // System tray: lets the app keep running (llama-server + the
            // unseen-message poll loop that drives notifications) after the
            // window is closed, with a way back in besides relaunching the
            // whole app from scratch.
            #[cfg(desktop)]
            {
                use tauri::menu::{Menu, MenuItem};
                use tauri::tray::TrayIconBuilder;

                let show_i = MenuItem::with_id(app, "show", "Open beuclub", true, None::<&str>)?;
                let quit_i = MenuItem::with_id(app, "quit", "Quit", true, None::<&str>)?;
                let menu = Menu::with_items(app, &[&show_i, &quit_i])?;

                TrayIconBuilder::new()
                    .icon(app.default_window_icon().unwrap().clone())
                    .menu(&menu)
                    .tooltip("beuclub")
                    .on_menu_event(|app, event| match event.id.as_ref() {
                        "show" => show_main_window(app),
                        "quit" => quit_app(app),
                        _ => {}
                    })
                    .on_tray_icon_event(|tray, event| {
                        if let tauri::tray::TrayIconEvent::Click {
                            button: tauri::tray::MouseButton::Left,
                            button_state: tauri::tray::MouseButtonState::Up,
                            ..
                        } = event
                        {
                            show_main_window(tray.app_handle());
                        }
                    })
                    .build(app)?;
            }

            // Closing the window now hides it instead of quitting the app --
            // llama-server keeps running and the frontend's unseen-message
            // poll loop (club.js) keeps ticking in the background, so a
            // Windows notification can still pop for a new DM/club message
            // even with the window closed. Only the tray's "Quit" (or OS
            // shutdown/task-manager kill) actually terminates the process
            // and the sidecar with it.
            let app_handle = app.handle().clone();
            window.on_window_event(move |event| {
                if let tauri::WindowEvent::CloseRequested { api, .. } = event {
                    if let Some(w) = app_handle.get_webview_window("main") {
                        let _ = w.hide();
                    }
                    api.prevent_close();
                }
            });

            Ok(())
        })
        .run(tauri::generate_context!())
        .expect("error while running!");
}
