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
          opentofuPackage =
            if pkgs ? opentofu then
              [ pkgs.opentofu ]
            else
              [ ];
        in
        {
          default = pkgs.mkShell {
            packages = [
              pkgs.nodejs_24
              pkgs.corepack
              pkgs.php
              pkgs.phpPackages.composer
              pkgs.wp-cli
              pkgs.podman
              pkgs.podman-compose
              pkgs.just
              pkgs.git
            ] ++ opentofuPackage;

            shellHook = ''
              export COREPACK_ENABLE_DOWNLOAD_PROMPT=0
              echo "recipes dev shell: run 'corepack enable', then 'yarn install' and 'composer install' when dependencies are needed."
            '';
          };
        });
    };
}
