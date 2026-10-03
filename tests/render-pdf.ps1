param(
    [Parameter(Mandatory = $true)][string]$InputPdf,
    [Parameter(Mandatory = $true)][string]$OutputDirectory
)

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Runtime.WindowsRuntime
[Windows.Storage.StorageFile, Windows.Storage, ContentType = WindowsRuntime] | Out-Null
[Windows.Data.Pdf.PdfDocument, Windows.Data.Pdf, ContentType = WindowsRuntime] | Out-Null
[Windows.Storage.Streams.IRandomAccessStream, Windows.Storage.Streams, ContentType = WindowsRuntime] | Out-Null

$asTask = ([System.WindowsRuntimeSystemExtensions].GetMethods() | Where-Object {
    $_.Name -eq 'AsTask' -and $_.IsGenericMethod -and
    $_.GetParameters().Count -eq 1 -and
    $_.GetParameters()[0].ParameterType.Name -eq 'IAsyncOperation`1'
})[0]
$asAction = ([System.WindowsRuntimeSystemExtensions].GetMethods() | Where-Object {
    $_.Name -eq 'AsTask' -and -not $_.IsGenericMethod -and
    $_.GetParameters().Count -eq 1 -and
    $_.GetParameters()[0].ParameterType.Name -eq 'IAsyncAction'
})[0]

function Await-WinRT($operation, $type) {
    $task = $asTask.MakeGenericMethod($type).Invoke($null, @($operation))
    $task.Wait()
    $task.Result
}

$file = Await-WinRT ([Windows.Storage.StorageFile]::GetFileFromPathAsync((Resolve-Path -LiteralPath $InputPdf).Path)) ([Windows.Storage.StorageFile])
$pdf = Await-WinRT ([Windows.Data.Pdf.PdfDocument]::LoadFromFileAsync($file)) ([Windows.Data.Pdf.PdfDocument])
New-Item -ItemType Directory -Path $OutputDirectory -Force | Out-Null
$folder = Await-WinRT ([Windows.Storage.StorageFolder]::GetFolderFromPathAsync((Resolve-Path -LiteralPath $OutputDirectory).Path)) ([Windows.Storage.StorageFolder])

for ($pageIndex = 0; $pageIndex -lt $pdf.PageCount; $pageIndex++) {
    $output = Await-WinRT ($folder.CreateFileAsync(('page-' + ($pageIndex + 1) + '.png'), [Windows.Storage.CreationCollisionOption]::ReplaceExisting)) ([Windows.Storage.StorageFile])
    $stream = Await-WinRT ($output.OpenAsync([Windows.Storage.FileAccessMode]::ReadWrite)) ([Windows.Storage.Streams.IRandomAccessStream])
    $page = $pdf.GetPage($pageIndex)
    $asAction.Invoke($null, @($page.RenderToStreamAsync($stream))).Wait()
    $stream.Dispose()
    $page.Dispose()
    Write-Output $output.Path
}
