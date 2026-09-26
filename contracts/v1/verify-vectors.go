// Independent byte/signature oracle for public test fixtures, not a production validator.
package main

import (
	"crypto/ed25519"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"strings"
)

type testKey struct {
	Name      string `json:"name"`
	Kind      string `json:"kind"`
	Seed      string `json:"seed_hex"`
	PublicKey string `json:"public_key_b64u"`
}

type licenseVector struct {
	Name         string `json:"name"`
	KeyName      string `json:"key_name"`
	HeaderJSON   string `json:"header_json"`
	PayloadJSON  string `json:"payload_json"`
	SigningInput string `json:"signing_input"`
	JWS          string `json:"jws"`
	SHA256       string `json:"sha256"`
}

type proofVector struct {
	Name           string  `json:"name"`
	KeyName        string  `json:"key_name"`
	Action         string  `json:"action"`
	ChallengeID    string  `json:"challenge_id"`
	Nonce          string  `json:"nonce"`
	ProductID      string  `json:"product_id"`
	InstallationID string  `json:"installation_id"`
	ActivationID   *string `json:"activation_id"`
	Message        string  `json:"message_utf8"`
	Proof          string  `json:"proof"`
}

type offlineVector struct {
	Name         string `json:"name"`
	KeyName      string `json:"key_name"`
	PayloadJSON  string `json:"payload_json"`
	Message      string `json:"message_utf8"`
	EnvelopeJSON string `json:"envelope_json"`
}

type fixtureSet struct {
	Version  string          `json:"fixture_version"`
	Keys     []testKey       `json:"keys"`
	Licenses []licenseVector `json:"licenses"`
	Proofs   []proofVector   `json:"proofs"`
	Offline  []offlineVector `json:"offline_requests"`
	Negative []struct {
		Name          string `json:"name"`
		JWS           string `json:"jws"`
		ExpectedError string `json:"expected_error"`
	} `json:"negative_jws"`
}

func require(condition bool, message string) {
	if !condition {
		fmt.Fprintln(os.Stderr, "FAIL:", message)
		os.Exit(1)
	}
}

func decodeBase64URL(value string) ([]byte, error) {
	decoded, err := base64.RawURLEncoding.Strict().DecodeString(value)
	if err != nil || base64.RawURLEncoding.EncodeToString(decoded) != value {
		return nil, fmt.Errorf("noncanonical base64url")
	}
	return decoded, nil
}

func verifyJWS(value string, publicKeys map[string]ed25519.PublicKey) (string, string) {
	segments := strings.Split(value, ".")
	if len(segments) != 3 {
		return "", "invalid_format"
	}
	headerBytes, headerError := decodeBase64URL(segments[0])
	payloadBytes, payloadError := decodeBase64URL(segments[1])
	signature, signatureError := decodeBase64URL(segments[2])
	if headerError != nil || payloadError != nil || signatureError != nil || len(signature) != ed25519.SignatureSize {
		return "", "invalid_format"
	}
	var header struct {
		Algorithm string `json:"alg"`
		KeyID     string `json:"kid"`
		Type      string `json:"typ"`
	}
	if json.Unmarshal(headerBytes, &header) != nil || header.Algorithm != "EdDSA" || header.Type != "lic+jws" {
		return "", "invalid_header"
	}
	publicKey, trusted := publicKeys[header.KeyID]
	if !trusted {
		return "", "unknown_kid"
	}
	if !ed25519.Verify(publicKey, []byte(segments[0]+"."+segments[1]), signature) {
		return "", "invalid_signature"
	}
	return string(payloadBytes), ""
}

func checkSignature(name, message, encodedSignature string, privateKey ed25519.PrivateKey) {
	signature, err := decodeBase64URL(encodedSignature)
	require(err == nil && len(signature) == ed25519.SignatureSize, name+": signature encoding")
	publicKey := privateKey.Public().(ed25519.PublicKey)
	require(ed25519.Verify(publicKey, []byte(message), signature), name+": PHP signature verified by Go")
	goSignature := base64.RawURLEncoding.EncodeToString(ed25519.Sign(privateKey, []byte(message)))
	require(goSignature == encodedSignature, name+": Go/PHP signatures byte-identical")
	require(!ed25519.Verify(publicKey, []byte(message+"\n"), signature), name+": appended LF rejected")
}

func main() {
	require(len(os.Args) == 2, "usage: go run verify-vectors.go fixtures/vectors.json")
	fixturePath := os.Args[1]
	fixtureBytes, err := os.ReadFile(fixturePath)
	require(err == nil, "read fixtures")
	var fixtures fixtureSet
	require(json.Unmarshal(fixtureBytes, &fixtures) == nil && fixtures.Version == "1.0", "fixture schema")
	require(len(fixtures.Keys) == 4 && len(fixtures.Licenses) == 5 && len(fixtures.Proofs) == 3 && len(fixtures.Offline) == 3 && len(fixtures.Negative) == 3, "expected fixture coverage")
	privateKeys := map[string]ed25519.PrivateKey{}
	serverPublicKeys := map[string]ed25519.PublicKey{}
	for _, key := range fixtures.Keys {
		seed, seedError := hex.DecodeString(key.Seed)
		require(seedError == nil && len(seed) == ed25519.SeedSize, key.Name+": test seed")
		expectedSeed := sha256.Sum256([]byte("AIBIDLICENSE PUBLIC TEST ONLY V1 " + key.Name))
		require(hex.EncodeToString(expectedSeed[:]) == key.Seed, key.Name+": public deterministic derivation")
		privateKey := ed25519.NewKeyFromSeed(seed)
		publicKey := privateKey.Public().(ed25519.PublicKey)
		require(base64.RawURLEncoding.EncodeToString(publicKey) == key.PublicKey, key.Name+": public key")
		privateKeys[key.Name] = privateKey
		if key.Kind == "server" {
			serverPublicKeys[key.Name] = publicKey
		}
	}
	for _, vector := range fixtures.Licenses {
		payload, errorCode := verifyJWS(vector.JWS, serverPublicKeys)
		require(errorCode == "" && payload == vector.PayloadJSON, vector.Name+": JWS verifies")
		expectedInput := base64.RawURLEncoding.EncodeToString([]byte(vector.HeaderJSON)) + "." + base64.RawURLEncoding.EncodeToString([]byte(vector.PayloadJSON))
		require(expectedInput == vector.SigningInput, vector.Name+": JWS exact bytes")
		segments := strings.Split(vector.JWS, ".")
		checkSignature(vector.Name, expectedInput, segments[2], privateKeys[vector.KeyName])
		digest := sha256.Sum256([]byte(vector.JWS))
		require(hex.EncodeToString(digest[:]) == vector.SHA256, vector.Name+": SHA-256")
		artifact, artifactError := os.ReadFile(filepath.Join(filepath.Dir(fixturePath), vector.Name+".lic"))
		require(artifactError == nil && string(artifact) == vector.JWS, vector.Name+": .lic exact bytes")
	}
	for _, vector := range fixtures.Proofs {
		activationID := "-"
		if vector.ActivationID != nil {
			activationID = *vector.ActivationID
		}
		message := strings.Join([]string{"LIC-V1", vector.Action, vector.ChallengeID, vector.Nonce, vector.ProductID, vector.InstallationID, activationID}, "\n")
		require(message == vector.Message, vector.Name+": independent proof construction")
		checkSignature(vector.Name, message, vector.Proof, privateKeys[vector.KeyName])
	}
	for _, vector := range fixtures.Offline {
		var envelope struct {
			Schema    string `json:"schema_version"`
			Payload   string `json:"payload_b64u"`
			Signature string `json:"signature_b64u"`
		}
		require(json.Unmarshal([]byte(vector.EnvelopeJSON), &envelope) == nil && envelope.Schema == "1.0", vector.Name+": .licreq envelope")
		payload, decodeError := decodeBase64URL(envelope.Payload)
		require(decodeError == nil && string(payload) == vector.PayloadJSON, vector.Name+": .licreq payload bytes")
		var identity struct {
			Action    string `json:"action"`
			PublicKey string `json:"installation_public_key"`
		}
		require(json.Unmarshal(payload, &identity) == nil && identity.Action == vector.Name, vector.Name+": .licreq action")
		privateKey := privateKeys[vector.KeyName]
		require(identity.PublicKey == base64.RawURLEncoding.EncodeToString(privateKey.Public().(ed25519.PublicKey)), vector.Name+": declared installation key")
		message := "LICREQ-V1\n" + envelope.Payload
		require(message == vector.Message, vector.Name+": independent .licreq construction")
		checkSignature(vector.Name, message, envelope.Signature, privateKey)
		artifact, artifactError := os.ReadFile(filepath.Join(filepath.Dir(fixturePath), vector.Name+".licreq"))
		require(artifactError == nil && string(artifact) == vector.EnvelopeJSON, vector.Name+": .licreq exact bytes")
	}
	for _, vector := range fixtures.Negative {
		_, errorCode := verifyJWS(vector.JWS, serverPublicKeys)
		require(errorCode == vector.ExpectedError, vector.Name+": expected rejection")
	}
	fmt.Println("OK: 5 JWS, 3 proofs, 3 offline requests, 3 negative JWS; PHP/Go signatures match.")
	fmt.Println("Client entitlement/state cases are specified in fixtures, not executed by this crypto oracle.")
}
