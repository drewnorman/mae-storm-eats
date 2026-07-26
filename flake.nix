{
  description = "Development environment for the Mae Storm recipes WordPress app";

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
              pkgs.opentofu
              pkgs.gnutar
              pkgs.gzip
            ];

            shellHook = ''
              export COREPACK_ENABLE_DOWNLOAD_PROMPT=0
              echo "recipes dev shell: run 'just bootstrap', then 'just dev-up'."
            '';
          };
        });
    };
}
