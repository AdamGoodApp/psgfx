// Minimal client for the PS5, through the PS5 Upload app on this PC.
//
// PS5 Upload 6.0 replaced its old protocol (FTX2, ports 9113/9114) with AVA1: one encrypted
// port (9120) that the console only opens to a paired app. Rather than pair a second app,
// PSGFX hands its calls to PS5 Upload's local engine (http://127.0.0.1:19113), which already
// holds the paired session: POST /api/mgmt/call runs one management call on the console, with
// the same JSON bodies the old protocol used. So PS5 Upload has to be open on this PC.
#pragma once
#include <cstdint>
#include <cstring>
#include <stdexcept>
#include <string>
#include <vector>
#include "lib/json.hpp"

#ifdef _WIN32
#include <winsock2.h>
#include <ws2tcpip.h>
typedef SOCKET sock_t;
#define SOCK_BAD INVALID_SOCKET
#define sock_close closesocket
#else
#include <arpa/inet.h>
#include <netdb.h>
#include <netinet/in.h>
#include <netinet/tcp.h>
#include <sys/socket.h>
#include <sys/time.h>
#include <unistd.h>
typedef int sock_t;
#define SOCK_BAD (-1)
#define sock_close close
#endif

namespace ps5 {

// AVA1 management methods (PS5 Upload's protocol/ava1 schema) and the labels its errors carry.
struct Method { uint16_t id; const char *label; };
constexpr Method FS_LIST{33, "FS_LIST_DIR"}, FS_MKDIR{35, "FS_MKDIR"}, FS_READ{38, "FS_READ"},
                 FS_WRITE{39, "FS_WRITE_BYTES"}, APP_REGISTER{48, "APP_REGISTER"},
                 APP_LIST{51, "APP_LIST_REGISTERED"}, APP_DB_QUERY{56, "APP_DB_QUERY"},
                 SHELL_EXEC{87, "SHELL_EXEC"};
constexpr uint8_t JOB_DELETE = 1;

struct Error : std::runtime_error { using std::runtime_error::runtime_error; };

inline uint32_t get32(const uint8_t *p) { return (uint32_t)p[0] | (uint32_t)p[1] << 8 | (uint32_t)p[2] << 16 | (uint32_t)p[3] << 24; }

inline std::string b64_encode(const uint8_t *d, size_t n) {
    static const char *T = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";
    std::string o; o.reserve((n + 2) / 3 * 4);
    for (size_t i = 0; i < n; i += 3) {
        uint32_t v = (uint32_t)d[i] << 16 | (i + 1 < n ? (uint32_t)d[i + 1] << 8 : 0) | (i + 2 < n ? d[i + 2] : 0);
        o += T[v >> 18 & 63]; o += T[v >> 12 & 63];
        o += i + 1 < n ? T[v >> 6 & 63] : '='; o += i + 2 < n ? T[v & 63] : '=';
    }
    return o;
}
inline std::string b64_encode(const std::string &s) { return b64_encode((const uint8_t *)s.data(), s.size()); }
inline std::vector<uint8_t> b64_decode(const std::string &s) {
    std::vector<uint8_t> o; o.reserve(s.size() / 4 * 3);
    uint32_t v = 0; int bits = 0;
    for (char ch : s) {
        int x = ch >= 'A' && ch <= 'Z' ? ch - 'A' : ch >= 'a' && ch <= 'z' ? ch - 'a' + 26
              : ch >= '0' && ch <= '9' ? ch - '0' + 52 : ch == '+' ? 62 : ch == '/' ? 63 : -1;
        if (x < 0) continue;
        v = v << 6 | (uint32_t)x; bits += 6;
        if (bits >= 8) { bits -= 8; o.push_back((uint8_t)(v >> bits)); }
    }
    return o;
}

// One HTTP/1.1 request to the local engine (Connection: close). Returns (status, body).
inline std::pair<int, std::string> http(const std::string &host, int port, const std::string &method,
                                        const std::string &path, const std::string &body, int timeout_ms) {
    addrinfo hints{}, *res = nullptr;
    hints.ai_family = AF_INET; hints.ai_socktype = SOCK_STREAM;
    if (getaddrinfo(host.c_str(), std::to_string(port).c_str(), &hints, &res) != 0 || !res)
        throw Error("Can't resolve " + host);
    sock_t s = socket(res->ai_family, res->ai_socktype, res->ai_protocol);
    if (s == SOCK_BAD) { freeaddrinfo(res); throw Error("socket() failed"); }
#ifdef _WIN32
    DWORD t = (DWORD)timeout_ms;
#else
    timeval t{timeout_ms / 1000, (timeout_ms % 1000) * 1000};
#endif
    setsockopt(s, SOL_SOCKET, SO_RCVTIMEO, (const char *)&t, sizeof t);
    setsockopt(s, SOL_SOCKET, SO_SNDTIMEO, (const char *)&t, sizeof t);
    if (connect(s, res->ai_addr, (int)res->ai_addrlen) != 0) {
        freeaddrinfo(res); sock_close(s);
        throw Error("PSGFX talks to your PS5 through the PS5 Upload app, and it isn't running on this PC. "
                    "Open PS5 Upload (version 6 or newer), make sure it's connected to your PS5, then click Connect again.");
    }
    freeaddrinfo(res);
    std::string req = method + " " + path + " HTTP/1.1\r\nHost: " + host + ":" + std::to_string(port) +
                      "\r\nContent-Type: application/json\r\nContent-Length: " + std::to_string(body.size()) +
                      "\r\nConnection: close\r\n\r\n" + body;
    for (size_t off = 0; off < req.size();) {
        int w = ::send(s, req.data() + off, (int)std::min<size_t>(req.size() - off, 1 << 20), 0);
        if (w <= 0) { sock_close(s); throw Error("The connection to PS5 Upload dropped."); }
        off += (size_t)w;
    }
    std::string resp; char buf[1 << 16];
    for (;;) {
        int r = ::recv(s, buf, sizeof buf, 0);
        if (r == 0) break;
        if (r < 0) { sock_close(s); throw Error("PS5 Upload didn't answer in time."); }
        resp.append(buf, (size_t)r);
    }
    sock_close(s);
    size_t he = resp.find("\r\n\r\n");
    if (resp.compare(0, 5, "HTTP/") != 0 || he == std::string::npos) throw Error("Unexpected reply from PS5 Upload.");
    int status = atoi(resp.c_str() + resp.find(' ') + 1);
    std::string head = resp.substr(0, he), out = resp.substr(he + 4);
    for (auto &ch : head) ch = (char)tolower((unsigned char)ch);
    if (head.find("transfer-encoding: chunked") != std::string::npos) {
        std::string d; size_t p = 0;
        for (;;) {
            size_t e = out.find("\r\n", p);
            if (e == std::string::npos) break;
            size_t n = strtoull(out.c_str() + p, nullptr, 16);
            if (n == 0 || e + 2 + n > out.size()) break;
            d.append(out, e + 2, n);
            p = e + 2 + n + 2;
        }
        out.swap(d);
    }
    return {status, out};
}

struct Client {
    std::string host;                                // the PS5's IP
    std::string engine_host = "127.0.0.1"; int engine_port = 19113;

    // One management call on the console. Throws ps5::Error with a readable message.
    std::vector<uint8_t> call(Method m, const std::string &body, int timeout_ms = 15000) {
        return proxy({{"op", "call"}, {"addr", host}, {"method", m.id}, {"label", m.label},
                      {"body_b64", b64_encode(body)}, {"timeout_ms", timeout_ms}}, m.label, timeout_ms);
    }
    std::string call_str(Method m, const std::string &body, int timeout_ms = 15000) {
        auto b = call(m, body, timeout_ms);
        return std::string(b.begin(), b.end());
    }

    void remove(const std::string &path) {
        std::string body = nlohmann::json{{"path", path}}.dump();
        proxy({{"op", "run_job"}, {"addr", host}, {"job_op", JOB_DELETE}, {"label", "FS_DELETE"},
               {"body_b64", b64_encode(body)}, {"op_id", 0}, {"subject", path}, {"deadline_ms", 30000}}, "FS_DELETE", 30000);
    }

    // Whole file, up to 16 MiB (PS5 Upload sends it in pieces and commits it once).
    void upload(const std::string &dest, const std::vector<uint8_t> &data) {
        if (data.size() > (16u << 20)) throw Error("That file is too large to send (over 16 MB).");
        call(FS_WRITE, nlohmann::json{{"path", dest}, {"bytes", b64_encode(data.data(), data.size())},
                                      {"mode", "overwrite"}, {"large", true}}.dump(), 60000);
    }

    // Up to `limit` bytes from `offset`. "unsafe" lets reads reach /system_data (app.db); the
    // payload still refuses anything outside its readable roots.
    std::vector<uint8_t> read(const std::string &path, uint64_t offset, uint64_t limit, int timeout_ms = 20000) {
        return call(FS_READ, nlohmann::json{{"path", path}, {"offset", offset}, {"limit", limit}, {"unsafe", true}}.dump(), timeout_ms);
    }
    std::vector<uint8_t> read_file(const std::string &path, size_t max_total = 64u << 20) {
        std::vector<uint8_t> out;
        const size_t piece = 8u << 20;
        for (;;) {
            auto part = read(path, out.size(), piece, 60000);
            out.insert(out.end(), part.begin(), part.end());
            if (part.size() < piece || out.size() >= max_total) break;
        }
        return out;
    }

    // PS5 Upload's console shell (its own built-in commands; there is no real shell on a PS5).
    nlohmann::json shell(const std::string &cmd, int timeout_ms = 30000) {
        return nlohmann::json::parse(call_str(SHELL_EXEC, nlohmann::json{{"cmd", cmd}, {"cwd", "/"}}.dump(), timeout_ms));
    }

  private:
    std::vector<uint8_t> proxy(const nlohmann::json &req, const std::string &label, int timeout_ms) {
        auto [status, body] = http(engine_host, engine_port, "POST", "/api/mgmt/call", req.dump(), timeout_ms + 10000);
        if (status == 404) throw Error("This version of PS5 Upload is too old for PSGFX. Update PS5 Upload to version 6 or newer.");
        nlohmann::json j;
        try { j = nlohmann::json::parse(body); }
        catch (...) { throw Error("PS5 Upload refused the request (HTTP " + std::to_string(status) + "): " + body.substr(0, 300)); }
        if (j.value("ok", false)) return b64_decode(j["result"].value("body_b64", ""));
        auto e = j.value("error", nlohmann::json::object());
        std::string cause = e.value("cause", e.value("message", std::string("unknown error")));
        if (cause.find("not_paired") != std::string::npos)
            throw Error("Your PS5 hasn't accepted PS5 Upload yet. Pair them on PS5 Upload's Connection screen, then try again.");
        if (cause.find("helper_not_ava1") != std::string::npos)
            throw Error("PS5 Upload's payload isn't running on the PS5 at " + host + " (or it's an old version). "
                        "Send it from PS5 Upload's Connection screen, then try again.");
        if (cause.find("ava1_unreachable") != std::string::npos || cause.find("did not answer") != std::string::npos)
            throw Error("Can't reach the PS5 at " + host + ". Check the IP, and that PS5 Upload can see the console.");
        throw Error(label + ": " + cause);
    }
};

} // namespace ps5
