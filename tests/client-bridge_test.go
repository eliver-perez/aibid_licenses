package licensing

// Copied only into an isolated snapshot of the real client by the PHP integration test.
// This adapter calls the original client methods; it contains no licensing implementation.
import (
    "context"
    "crypto/x509"
    "encoding/json"
    "os"
    "net/http"
    "testing"
    "time"
    "gestor-documental/internal/domain"
    "gestor-documental/internal/storage"
)

func TestServerBridge(t *testing.T) {
    path := os.Getenv("AIBID_BRIDGE_JOB")
    if path == "" { t.Skip("Only used by the isolated PHP integration runner") }
    var job struct {
        Directory string `json:"directory"`
        Origin string `json:"origin"`
        CA string `json:"ca"`
        Output string `json:"output"`
        Keys []TrustKey `json:"keys"`
        Operation string `json:"operation"`
        Action string `json:"action"`
        RequestID string `json:"request_id"`
        CommercialKey string `json:"commercial_key"`
        Contents string `json:"contents"`
        Now string `json:"now"`
    }
    contents, err := os.ReadFile(path); if err != nil { t.Fatal("Cannot read isolated job") }
    if json.Unmarshal(contents, &job) != nil { t.Fatal("Invalid isolated job") }
    ctx := context.Background()
    database, err := storage.Open(ctx, job.Directory); if err != nil { t.Fatal(err) }
    defer database.Close()
    client, err := New(database, job.Directory, Options{ServerURL:job.Origin, TrustedKeys:job.Keys})
    if err != nil { t.Fatal(err) }
    if job.Now != "" {
        instant, err := time.Parse(time.RFC3339Nano,job.Now); if err != nil { t.Fatal(err) }
        client.Now = func() time.Time { return instant }
    }
    // The real production transport still verifies TLS; trust only this temporary test CA.
    certificate, err := os.ReadFile(job.CA); if err != nil { t.Fatal(err) }
    roots := x509.NewCertPool(); if !roots.AppendCertsFromPEM(certificate) { t.Fatal("Invalid test CA") }
    client.httpClient.Transport.(*http.Transport).TLSClientConfig.RootCAs = roots
    result := map[string]any{}
    var status Status
    switch job.Operation {
    case "online": status, err = client.Online(ctx,job.Action,job.RequestID,job.CommercialKey,nil,domain.RequestMetadata{})
    case "offline":
        var artifact Artifact
        artifact, err = client.OfflineRequest(ctx,job.Action,job.RequestID,nil,domain.RequestMetadata{})
        result["contents"] = artifact.Contents
        if err == nil { status, err = client.Status(ctx) }
    case "import": status, err = client.Import(ctx,[]byte(job.Contents),nil,domain.RequestMetadata{})
    case "status": status, err = client.Status(ctx)
    default: t.Fatal("Unknown bridge operation")
    }
    if err != nil { result["error"] = errorCode(err) } else { result["status"] = status }
    result["read_allowed"] = client.Check(ctx,ReadDocuments)==nil
    result["write_allowed"] = client.Check(ctx,WriteDocuments)==nil
    result["export_allowed"] = client.Check(ctx,BackupExport)==nil
    result["ocr_allowed"] = client.CheckFeatures(ctx,"ocr")==nil
    bytes, err := json.Marshal(result); if err != nil { t.Fatal(err) }
    if os.WriteFile(job.Output,bytes,0600)!=nil { t.Fatal("Cannot write isolated result") }
}
