// PSGFX on macOS: one window with the UI in a WKWebView, so nothing opens in the browser.
// main.cpp starts the web server on a thread, then calls mac_run_app(url) on the main thread.
#import <Cocoa/Cocoa.h>
#import <WebKit/WebKit.h>
#include <string>

@interface PSGFXApp : NSObject <NSApplicationDelegate, WKUIDelegate, WKNavigationDelegate>
@property (strong) NSWindow *window;
@property (strong) WKWebView *web;
@property (strong) NSURL *url;
@end

@implementation PSGFXApp
- (void)applicationDidFinishLaunching:(NSNotification *)n {
    self.web = [[WKWebView alloc] initWithFrame:NSMakeRect(0, 0, 1280, 800) configuration:[WKWebViewConfiguration new]];
    self.web.UIDelegate = self; self.web.navigationDelegate = self;
    NSWindowStyleMask style = NSWindowStyleMaskTitled | NSWindowStyleMaskClosable | NSWindowStyleMaskMiniaturizable | NSWindowStyleMaskResizable;
    self.window = [[NSWindow alloc] initWithContentRect:NSMakeRect(0, 0, 1280, 800) styleMask:style backing:NSBackingStoreBuffered defer:NO];
    self.window.title = @"PSGFX"; self.window.minSize = NSMakeSize(900, 600);
    self.window.releasedWhenClosed = NO;   // ARC owns it via the strong property
    self.window.contentView = self.web;
    if (![self.window setFrameUsingName:@"PSGFXMain"]) [self.window center];   // first launch: centered; later: where it was left
    [self.window setFrameAutosaveName:@"PSGFXMain"];
    [self.window makeKeyAndOrderFront:nil];
    [NSApp activateIgnoringOtherApps:YES];
    [self.web loadRequest:[NSURLRequest requestWithURL:self.url]];
}
- (BOOL)applicationShouldTerminateAfterLastWindowClosed:(NSApplication *)a { return YES; }   // closing the window quits
- (BOOL)applicationShouldHandleReopen:(NSApplication *)a hasVisibleWindows:(BOOL)v { [self.window makeKeyAndOrderFront:nil]; return NO; }
- (void)openInBrowser:(id)sender { [[NSWorkspace sharedWorkspace] openURL:self.url]; }

// Links to other sites open in the default browser; the UI stays in this window.
- (void)webView:(WKWebView *)w decidePolicyForNavigationAction:(WKNavigationAction *)a decisionHandler:(void (^)(WKNavigationActionPolicy))h {
    NSURL *u = a.request.URL;
    BOOL ours = [u.host isEqualToString:self.url.host] && [u.port isEqualToNumber:self.url.port];
    if (!ours && ([u.scheme isEqualToString:@"http"] || [u.scheme isEqualToString:@"https"])) {
        [[NSWorkspace sharedWorkspace] openURL:u]; h(WKNavigationActionPolicyCancel); return;
    }
    h(WKNavigationActionPolicyAllow);
}
- (WKWebView *)webView:(WKWebView *)w createWebViewWithConfiguration:(WKWebViewConfiguration *)c forNavigationAction:(WKNavigationAction *)a windowFeatures:(WKWindowFeatures *)f {
    [[NSWorkspace sharedWorkspace] openURL:a.request.URL]; return nil;   // target="_blank" links
}
// The UI's "Choose image" / "Choose song" buttons (<input type="file">).
- (void)webView:(WKWebView *)w runOpenPanelWithParameters:(WKOpenPanelParameters *)p initiatedByFrame:(WKFrameInfo *)f completionHandler:(void (^)(NSArray<NSURL *> *))h {
    NSOpenPanel *op = [NSOpenPanel openPanel];
    op.allowsMultipleSelection = p.allowsMultipleSelection; op.canChooseDirectories = NO; op.canChooseFiles = YES;
    [op beginSheetModalForWindow:self.window completionHandler:^(NSModalResponse r) { h(r == NSModalResponseOK ? op.URLs : nil); }];
}
// alert() and confirm(), which the UI uses around layout changes.
- (void)webView:(WKWebView *)w runJavaScriptAlertPanelWithMessage:(NSString *)m initiatedByFrame:(WKFrameInfo *)f completionHandler:(void (^)(void))h {
    NSAlert *al = [NSAlert new]; al.messageText = m; [al addButtonWithTitle:@"OK"];
    [al beginSheetModalForWindow:self.window completionHandler:^(NSModalResponse r) { h(); }];
}
- (void)webView:(WKWebView *)w runJavaScriptConfirmPanelWithMessage:(NSString *)m initiatedByFrame:(WKFrameInfo *)f completionHandler:(void (^)(BOOL))h {
    NSAlert *al = [NSAlert new]; al.messageText = m; [al addButtonWithTitle:@"OK"]; [al addButtonWithTitle:@"Cancel"];
    [al beginSheetModalForWindow:self.window completionHandler:^(NSModalResponse r) { h(r == NSAlertFirstButtonReturn); }];
}
@end

static void add(NSMenu *m, NSString *title, SEL action, NSString *key) { [m addItemWithTitle:title action:action keyEquivalent:key]; }
static void submenu(NSMenu *bar, NSMenu *m) { NSMenuItem *i = [NSMenuItem new]; i.submenu = m; [bar addItem:i]; }

void mac_run_app(const std::string &url) {
    @autoreleasepool {
        NSApplication *app = [NSApplication sharedApplication];
        [app setActivationPolicy:NSApplicationActivationPolicyRegular];
        PSGFXApp *d = [PSGFXApp new]; d.url = [NSURL URLWithString:@(url.c_str())];
        NSMenu *bar = [NSMenu new];
        NSMenu *m = [[NSMenu alloc] initWithTitle:@"PSGFX"];
        add(m, @"Open in Browser", @selector(openInBrowser:), @"");
        [m addItem:[NSMenuItem separatorItem]];
        add(m, @"Quit PSGFX", @selector(terminate:), @"q");
        submenu(bar, m);
        NSMenu *e = [[NSMenu alloc] initWithTitle:@"Edit"];   // so Cmd-Z/X/C/V/A reach the web view's text fields
        add(e, @"Undo", @selector(undo:), @"z"); add(e, @"Redo", @selector(redo:), @"Z");
        [e addItem:[NSMenuItem separatorItem]];
        add(e, @"Cut", @selector(cut:), @"x"); add(e, @"Copy", @selector(copy:), @"c"); add(e, @"Paste", @selector(paste:), @"v"); add(e, @"Select All", @selector(selectAll:), @"a");
        submenu(bar, e);
        NSMenu *wm = [[NSMenu alloc] initWithTitle:@"Window"];
        add(wm, @"Minimize", @selector(performMiniaturize:), @"m"); add(wm, @"Close", @selector(performClose:), @"w");
        submenu(bar, wm);
        app.mainMenu = bar; app.delegate = d;
        [app run];
    }
}
