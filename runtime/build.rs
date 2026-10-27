fn main() {
    println!(
        "cargo:rustc-env=KIT_TARGET={}",
        std::env::var("TARGET").unwrap()
    );
}
