// The app lock, my choice in Phone › Settings: off, my fingerprint (the phone's own PIN or
// pattern behind it), or the app's own PIN — and how soon it locks again after I leave.

import 'dart:async';
import 'dart:convert';
import 'dart:math';

import 'package:crypto/crypto.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:local_auth/local_auth.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'main.dart';

class Lock {
  static const modes = {'off': 'বন্ধ', 'bio': 'আঙুলের ছাপ', 'pin': 'App PIN'};
  static const afters = {0: 'সাথে সাথে', 1: '১ মিনিট', 5: '৫ মিনিট', 15: '১৫ মিনিট'};

  static Future<SharedPreferences> get _p => SharedPreferences.getInstance();

  static Future<String> mode() async => (await _p).getString('lock.mode') ?? 'off';
  static Future<void> setMode(String value) async => (await _p).setString('lock.mode', value);
  static Future<int> after() async => (await _p).getInt('lock.after') ?? 1;
  static Future<void> setAfter(int minutes) async => (await _p).setInt('lock.after', minutes);

  static String _hash(String salt, String pin) => sha256.convert(utf8.encode('$salt:$pin')).toString();

  static Future<void> setPin(String pin) async {
    final p = await _p;
    final salt = base64Url.encode(List<int>.generate(16, (_) => Random.secure().nextInt(256)));
    await p.setString('lock.salt', salt);
    await p.setString('lock.pin', _hash(salt, pin));
  }

  static Future<bool> checkPin(String pin) async {
    final p = await _p;
    final salt = p.getString('lock.salt');
    final hash = p.getString('lock.pin');
    return salt != null && hash != null && _hash(salt, pin) == hash;
  }

  static Future<bool> hasPin() async => (await _p).getString('lock.pin') != null;

  /// My fingerprint, or the phone's own PIN/pattern behind it. A phone with no screen lock passes.
  static Future<bool> finger(String reason) async {
    final auth = LocalAuthentication();
    try {
      if (!await auth.isDeviceSupported()) {
        return true;
      }
      return await auth.authenticate(localizedReason: reason, options: const AuthenticationOptions(stickyAuth: true));
    } on PlatformException catch (e) {
      return e.code == 'NotAvailable' || e.code == 'PasscodeNotSet' || e.code == 'NotEnrolled';
    }
  }
}

/// Whatever the lock is now (before changing the lock itself).
Future<bool> confirmIdentity(BuildContext context, String reason) async {
  final mode = await Lock.mode();
  if (mode == 'off') {
    return true;
  }
  if (mode == 'bio') {
    return Lock.finger(reason);
  }
  if (!context.mounted) {
    return false;
  }
  return await Navigator.of(context).push<bool>(MaterialPageRoute(builder: (_) => PinPage(title: reason))) ?? false;
}

/// Sets a new app PIN (asked twice). @return true when set.
Future<bool> newPin(BuildContext context) async =>
    await Navigator.of(context).push<bool>(MaterialPageRoute(builder: (_) => const NewPinPage())) ?? false;

class LockGate extends StatefulWidget {
  const LockGate({super.key, required this.child});

  final Widget child;

  @override
  State<LockGate> createState() => _LockGateState();
}

class _LockGateState extends State<LockGate> with WidgetsBindingObserver {
  bool locked = false;
  bool asking = false;
  String mode = 'off';
  DateTime? leftAt;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    Lock.mode().then((m) {
      mode = m;
      if (m != 'off') {
        setState(() => locked = true);
        if (m == 'bio') unlock();
      }
    });
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) async {
    // The fingerprint prompt itself sends the app to the background for a moment.
    if (asking) {
      return;
    }
    if (state == AppLifecycleState.paused) {
      leftAt = DateTime.now();
    } else if (state == AppLifecycleState.resumed && leftAt != null) {
      final away = DateTime.now().difference(leftAt!);
      leftAt = null;
      mode = await Lock.mode();
      if (mode != 'off' && away >= Duration(minutes: await Lock.after())) {
        setState(() => locked = true);
        if (mode == 'bio') unlock();
      }
      unawaited(nativeChannel.invokeMethod('poll').catchError((_) => null));
    }
  }

  Future<void> unlock() async {
    if (asking) {
      return;
    }
    asking = true;
    final ok = await Lock.finger('MH-Bot খুলতে');
    asking = false;
    if (mounted) {
      setState(() => locked = !ok);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Stack(
      children: [
        widget.child,
        if (locked)
          Positioned.fill(
            child: Material(
              color: ink,
              child: mode == 'pin'
                  ? PinPad(
                      title: 'PIN দিয়ে খুলুন',
                      onDone: (pin) async {
                        final ok = await Lock.checkPin(pin);
                        if (ok && mounted) setState(() => locked = false);
                        return ok;
                      },
                    )
                  : SafeArea(
                      child: Center(
                        child: Column(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            const Icon(Icons.fingerprint, size: 76, color: Color(0xFF5EEAD4)),
                            const SizedBox(height: 18),
                            const Text('MH-Bot', style: TextStyle(fontSize: 26, fontWeight: FontWeight.w800, color: Colors.white)),
                            const SizedBox(height: 28),
                            FilledButton.icon(
                              style: FilledButton.styleFrom(backgroundColor: teal, padding: const EdgeInsets.symmetric(horizontal: 22, vertical: 14)),
                              onPressed: unlock,
                              icon: const Icon(Icons.lock_open),
                              label: const Text('খুলুন'),
                            ),
                          ],
                        ),
                      ),
                    ),
            ),
          ),
      ],
    );
  }
}

/// Four to six digits. Five wrong tries in a row wait thirty seconds.
class PinPad extends StatefulWidget {
  const PinPad({super.key, required this.title, required this.onDone, this.subtitle});

  final String title;
  final String? subtitle;
  final Future<bool> Function(String pin) onDone;

  @override
  State<PinPad> createState() => _PinPadState();
}

class _PinPadState extends State<PinPad> {
  String pin = '';
  String? error;
  int wrong = 0;
  DateTime? waitUntil;

  Future<void> press(String key) async {
    if (waitUntil != null && DateTime.now().isBefore(waitUntil!)) {
      setState(() => error = '${waitUntil!.difference(DateTime.now()).inSeconds + 1} সেকেন্ড অপেক্ষা করুন');
      return;
    }
    HapticFeedback.selectionClick();
    if (key == '<') {
      setState(() => pin = pin.isEmpty ? '' : pin.substring(0, pin.length - 1));
      return;
    }
    if (key == 'ok') {
      if (pin.length < 4) {
        setState(() => error = 'কমপক্ষে ৪ সংখ্যা');
        return;
      }
      final ok = await widget.onDone(pin);
      if (!mounted) return;
      if (ok) {
        wrong = 0;
        setState(() => pin = '');
      } else {
        wrong++;
        HapticFeedback.heavyImpact();
        if (wrong >= 5) {
          wrong = 0;
          waitUntil = DateTime.now().add(const Duration(seconds: 30));
        }
        setState(() {
          pin = '';
          error = 'PIN মেলেনি';
        });
      }
      return;
    }
    if (pin.length < 6) {
      setState(() {
        pin += key;
        error = null;
      });
    }
  }

  Widget key(String label, {IconData? icon}) => SizedBox(
        width: 76,
        height: 76,
        child: TextButton(
          style: TextButton.styleFrom(foregroundColor: Colors.white, shape: const CircleBorder()),
          onPressed: () => press(label),
          child: icon != null ? Icon(icon, size: 26) : Text(label, style: const TextStyle(fontSize: 26, fontWeight: FontWeight.w500)),
        ),
      );

  @override
  Widget build(BuildContext context) {
    return ColoredBox(
      color: ink,
      child: SafeArea(
        child: Column(
          children: [
            const Spacer(),
            const Icon(Icons.lock_outline, color: Color(0xFF5EEAD4), size: 40),
            const SizedBox(height: 14),
            Text(widget.title, style: const TextStyle(color: Colors.white, fontSize: 19, fontWeight: FontWeight.w600)),
            if (widget.subtitle != null) ...[
              const SizedBox(height: 6),
              Text(widget.subtitle!, style: const TextStyle(color: Colors.white60, fontSize: 13.5)),
            ],
            const SizedBox(height: 22),
            Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: List.generate(
                6,
                (i) => Container(
                  margin: const EdgeInsets.symmetric(horizontal: 7),
                  width: 13,
                  height: 13,
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    color: i < pin.length ? const Color(0xFF5EEAD4) : Colors.transparent,
                    border: Border.all(color: i < 4 || i < pin.length ? Colors.white54 : Colors.white24, width: 1.5),
                  ),
                ),
              ),
            ),
            SizedBox(height: 28, child: Center(child: Text(error ?? '', style: const TextStyle(color: Color(0xFFFCA5A5))))),
            for (final row in [
              ['1', '2', '3'],
              ['4', '5', '6'],
              ['7', '8', '9'],
            ])
              Row(mainAxisAlignment: MainAxisAlignment.center, children: row.map((k) => key(k)).toList()),
            Row(mainAxisAlignment: MainAxisAlignment.center, children: [key('<', icon: Icons.backspace_outlined), key('0'), key('ok', icon: Icons.check_rounded)]),
            const Spacer(),
          ],
        ),
      ),
    );
  }
}

/// A PIN check as its own screen (before changing the lock).
class PinPage extends StatelessWidget {
  const PinPage({super.key, required this.title});

  final String title;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: ink,
      body: PinPad(
        title: 'PIN দিন',
        subtitle: title,
        onDone: (pin) async {
          final ok = await Lock.checkPin(pin);
          if (ok && context.mounted) Navigator.of(context).pop(true);
          return ok;
        },
      ),
    );
  }
}

/// A new PIN, typed twice.
class NewPinPage extends StatefulWidget {
  const NewPinPage({super.key});

  @override
  State<NewPinPage> createState() => _NewPinPageState();
}

class _NewPinPageState extends State<NewPinPage> {
  String? first;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: ink,
      body: PinPad(
        key: ValueKey(first == null),
        title: first == null ? 'নতুন PIN দিন' : 'আবার একই PIN দিন',
        subtitle: '৪ থেকে ৬ সংখ্যা',
        onDone: (pin) async {
          if (first == null) {
            setState(() => first = pin);
            return true;
          }
          if (pin != first) {
            setState(() => first = null);
            return false;
          }
          await Lock.setPin(pin);
          if (context.mounted) Navigator.of(context).pop(true);
          return true;
        },
      ),
    );
  }
}
