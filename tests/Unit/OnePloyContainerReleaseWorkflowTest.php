<?php

use Symfony\Component\Yaml\Yaml;

function onePloyContainerReleaseWorkflow(): array
{
    $path = dirname(__DIR__, 2).'/.github/workflows/oneploy-release.yml';

    return [
        'source' => file_get_contents($path),
        'definition' => Yaml::parseFile($path),
    ];
}

it('publishes stable semantic versions from main or a version tag on main history', function () {
    ['source' => $workflow, 'definition' => $definition] = onePloyContainerReleaseWorkflow();

    expect(array_keys($definition['on']))->toBe(['push', 'workflow_dispatch'])
        ->and($definition['on']['push']['tags'])->toBe(['v*.*.*'])
        ->and($definition['on']['workflow_dispatch']['inputs']['version']['required'])->toBeTrue()
        ->and($definition['on']['workflow_dispatch']['inputs']['version']['type'])->toBe('string')
        ->and($definition['concurrency']['cancel-in-progress'])->toBeFalse()
        ->and($workflow)
        ->toContain('refs/heads/main')
        ->toContain('git merge-base --is-ancestor')
        ->toContain('The release tag must point to a commit contained in main.')
        ->toContain('Stable SemVer versions must use X.Y.Z without a leading v or zero-padded numbers.')
        ->toContain('docker buildx imagetools inspect "$reference"')
        ->toContain('Release tag already exists and will not be overwritten')
        ->toContain('Unable to prove that release tag is available')
        ->not->toContain(':latest');
});

it('uses least privilege and immutable third party action references', function () {
    ['source' => $workflow, 'definition' => $definition] = onePloyContainerReleaseWorkflow();

    expect($definition['permissions'])->toBe([])
        ->and($definition['jobs']['release']['permissions'])->toBe([
            'contents' => 'read',
            'packages' => 'write',
            'id-token' => 'write',
        ]);

    preg_match_all('/^\s*uses:\s+([^\s#]+)/m', $workflow, $actionMatches);
    foreach ($actionMatches[1] as $actionReference) {
        expect($actionReference)->toMatch('/@[a-f0-9]{40}$/');
    }

    preg_match_all('/secrets\.([A-Z0-9_]+)/', $workflow, $secretMatches);

    expect($actionMatches[1])->not->toBeEmpty()
        ->and(array_values(array_unique($secretMatches[1])))->toBe(['GITHUB_TOKEN']);
});

it('builds scans signs and promotes both multi architecture images by digest', function () {
    ['source' => $workflow] = onePloyContainerReleaseWorkflow();

    expect($workflow)
        ->toContain('file: ./docker/production/Dockerfile')
        ->toContain('file: ./docker/coolify-realtime/Dockerfile')
        ->toContain('platforms: linux/amd64,linux/arm64')
        ->toContain('provenance: mode=max')
        ->toContain('sbom: true')
        ->toContain('image-ref: ${{ steps.prepare.outputs.app_image }}@${{ steps.build_app.outputs.digest }}')
        ->toContain('image-ref: ${{ steps.prepare.outputs.realtime_image }}@${{ steps.build_realtime.outputs.digest }}')
        ->toContain('cosign sign --yes "${APP_IMAGE}@${APP_DIGEST}"')
        ->toContain('cosign sign --yes "${REALTIME_IMAGE}@${REALTIME_DIGEST}"')
        ->toContain('docker buildx imagetools create --tag "${APP_IMAGE}:${VERSION}" "${APP_IMAGE}@${APP_DIGEST}"')
        ->toContain('docker buildx imagetools create --tag "${REALTIME_IMAGE}:${VERSION}" "${REALTIME_IMAGE}@${REALTIME_DIGEST}"')
        ->toContain('Published digest does not match the scanned and signed digest')
        ->toContain('Pull by digest (recommended)');

    expect(substr_count($workflow, 'TRIVY_PLATFORM: linux/amd64'))->toBe(2)
        ->and(substr_count($workflow, 'TRIVY_PLATFORM: linux/arm64'))->toBe(2);
});
