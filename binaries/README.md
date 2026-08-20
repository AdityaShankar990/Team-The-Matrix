# llama-server sidecar binary

Tauri bundles this as a sidecar (see `externalBin` in `../tauri.conf.json`
and the spawn code in `../src/lib.rs`). It is NOT included in this archive
(no compiled binaries are shipped from this chat):

1. Download a `llama-server` build from the llama.cpp releases page
   (https://github.com/ggml-org/llama.cpp/releases) — grab the Windows
   CUDA/Vulkan build if you have a GPU, otherwise the plain CPU build.
2. Find your Rust target triple:
   `rustc -Vv | findstr host`  (Windows) → e.g. `x86_64-pc-windows-msvc`

Tauri strips the `-<target-triple>` suffix automatically at runtime; the
Rust code just calls `.sidecar("llama-server")`.
