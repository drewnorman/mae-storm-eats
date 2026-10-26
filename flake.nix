{
  description = "Development environment for the Mae Storm Eats WordPress app";

  inputs = {
    nixpkgs.url = "github:NixOS/nixpkgs/nixos-unstable";
  };

  outputs = { nixpkgs, ... }:
    let
      systems = [
        "aarch64-darwin"
        "aarch64-linux"
        "x86_64-darwin"
        "x86_64-linux"
      ];

      forAllSystems = nixpkgs.lib.genAttrs systems;
    in
    {
      formatter = forAllSystems (system: (import nixpkgs { inherit system; }).nixfmt);

      checks = forAllSystems (system:
        let pkgs = import nixpkgs { inherit system; };
        in {
          source = pkgs.runCommand "mae-storm-eats-source-check" { } ''
            test -f ${./package.json}
            touch $out
          '';
        });

      devShells = forAllSystems (system:
        let
          pkgs = import nixpkgs { inherit system; };
        in
        {
          default = pkgs.mkShell {
            packages = [
              pkgs.nodejs_24
              pkgs.corepack
              pkgs.php83
              pkgs.php83Packages.composer
              pkgs.wp-cli
              pkgs.podman
              pkgs.podman-compose
              pkgs.just
              pkgs.git
              pkgs.ripgrep
              pkgs.opentofu
              pkgs.gnutar
              pkgs.gzip
            ];

            shellHook = ''
              export COREPACK_ENABLE_DOWNLOAD_PROMPT=0
              echo "mae-storm-eats dev shell: run 'just bootstrap', then 'just dev-up'."
            '';
          };
        });
    };
}
