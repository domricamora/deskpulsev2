"""Shared dark blue/teal QSS theme, matching the DeskPulse web brand.
Applied once, globally, in main.py — individual windows don't set their own colors."""

BG = "#070b14"
SURFACE = "#111a2e"
SURFACE2 = "#0b111e"
BORDER = "#233149"
TEXT = "#e9eef8"
MUTED = "#94a3bd"
BRAND = "#3b82f6"
BRAND_DK = "#2563eb"
TEAL = "#2dd4bf"

DARK_QSS = f"""
QWidget {{
  background-color: {BG};
  color: {TEXT};
  font-family: "Inter", "Segoe UI", sans-serif;
  font-size: 13px;
}}
QLabel {{ background: transparent; }}
QPushButton {{
  background-color: {BRAND};
  color: #ffffff;
  border: 1px solid {BRAND_DK};
  border-radius: 4px;
  padding: 7px 14px;
  font-weight: 600;
}}
QPushButton:hover {{ background-color: {BRAND_DK}; }}
QPushButton:pressed {{ background-color: {TEAL}; color: #06121f; }}
QPushButton:disabled {{ background-color: {SURFACE}; color: {MUTED}; border-color: {BORDER}; }}
QComboBox, QLineEdit {{
  background-color: {SURFACE2};
  color: {TEXT};
  border: 1px solid {BORDER};
  border-radius: 4px;
  padding: 5px 8px;
  selection-background-color: {BRAND};
}}
QComboBox:focus, QLineEdit:focus {{ border: 1px solid {TEAL}; }}
QComboBox::drop-down {{ border: none; }}
QComboBox QAbstractItemView {{
  background-color: {SURFACE};
  color: {TEXT};
  border: 1px solid {BORDER};
  selection-background-color: {BRAND};
}}
QMenu {{
  background-color: {SURFACE};
  color: {TEXT};
  border: 1px solid {BORDER};
}}
QMenu::item {{ padding: 5px 18px; }}
QMenu::item:selected {{ background-color: {BRAND}; }}
QMessageBox {{ background-color: {SURFACE}; }}
QInputDialog {{ background-color: {SURFACE}; }}
QToolTip {{
  background-color: {SURFACE2};
  color: {TEXT};
  border: 1px solid {BORDER};
  padding: 4px;
}}
"""
