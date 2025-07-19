import mysql.connector
from transformers import pipeline
from datetime import datetime
import smtplib
from email.mime.text import MIMEText
from email.mime.multipart import MIMEMultipart
import time

# Initialize the sentiment analysis pipeline
sentiment_pipeline = pipeline("sentiment-analysis")

# Database connection configuration
db_config = {
    'host': 'localhost',
    'user': 'root',
    'password': '',  # Replace with your DB password
    'database': 'companionx'  # Updated to match the new database name
}

def modify_mood_entries_table():
    """
    Add mood_status and analyzed_at columns to mood_entries table if they don't exist.
    """
    connection = mysql.connector.connect(**db_config)
    cursor = connection.cursor()

    # Add mood_status column
    cursor.execute("""
        ALTER TABLE mood_entries
        ADD COLUMN mood_status VARCHAR(20) DEFAULT NULL,
        ADD COLUMN analyzed_at DATETIME DEFAULT NULL
    """)
    connection.commit()

    print("mood_entries table schema updated with mood_status and analyzed_at columns.")

    cursor.close()
    connection.close()

def fetch_new_mood_entries():
    """
    Fetch mood entries that haven't been analyzed yet.
    """
    connection = mysql.connector.connect(**db_config)
    cursor = connection.cursor(dictionary=True)

    query = "SELECT id, user_id, mood_note FROM mood_entries WHERE analyzed_at IS NULL"
    cursor.execute(query)
    entries = cursor.fetchall()

    print(f"Fetched Entries: {entries}")  # Debug log

    cursor.close()
    connection.close()
    return entries

def update_mood_entry(entry_id, mood_status):
    """
    Update the mood entry with the detected mood status and analysis timestamp.
    """
    connection = mysql.connector.connect(**db_config)
    cursor = connection.cursor()

    query = """
        UPDATE mood_entries
        SET mood_status = %s, analyzed_at = %s
        WHERE id = %s
    """
    cursor.execute(query, (mood_status, datetime.now(), entry_id))
    connection.commit()

    print(f"Updated Entry ID {entry_id} with mood_status = {mood_status} and analyzed_at = {datetime.now()}")  # Debug log

    cursor.close()
    connection.close()

def send_admin_notification(user_id, mood_note):
    """
    Notify the admin about critical mood entries via email.
    """
    admin_email = "sakter221467@bscse.uiu.ac.bd"  # Updated to match the admin email in the admins table
    sender_email = "projectcompanion4@gmail.com"  # Replace with your sender email
    sender_password = "qsrf pqku yesw yhnr"  # Replace with your Gmail app password

    subject = "URGENT: Potential Suicidal Intent Detected"
    body = f"""
    Dear Admin,

    A potential suicidal intent has been detected in the mood entry of User ID: {user_id}.

    Mood Note:
    {mood_note}

    Please intervene immediately to ensure the user's safety.

    Regards,
    CompanionX Support System
    """

    msg = MIMEMultipart()
    msg['From'] = sender_email
    msg['To'] = admin_email
    msg['Subject'] = subject
    msg.attach(MIMEText(body, 'plain'))

    try:
        with smtplib.SMTP('smtp.gmail.com', 587) as server:
            server.starttls()
            server.login(sender_email, sender_password)
            server.sendmail(sender_email, admin_email, msg.as_string())
            print("Admin notification sent successfully!")
    except Exception as e:
        print(f"Failed to send notification: {e}")

def analyze_mood_entries():
    """
    Analyze new mood entries and update their mood status.
    """
    # Ensure the table has the required columns
    try:
        modify_mood_entries_table()
    except mysql.connector.Error as e:
        print(f"Schema modification skipped (columns may already exist): {e}")

    entries = fetch_new_mood_entries()

    if not entries:
        print("No new entries to analyze.")
        return

    for entry in entries:
        print(f"Analyzing Entry ID: {entry['id']} for User ID: {entry['user_id']}")
        mood_note = entry['mood_note']
        if not mood_note or mood_note.strip() == "":
            print(f"Skipping Entry ID {entry['id']}: Empty or invalid mood_note")
            update_mood_entry(entry['id'], "neutral")
            continue

        result = sentiment_pipeline(mood_note)[0]
        print(f"Sentiment Result for Entry ID {entry['id']}: {result}")  # Debug log

        sentiment = result['label']
        confidence = result['score']

        # Map sentiment to mood_status
        if sentiment == "POSITIVE":
            mood_status = "happy"
        elif sentiment == "NEUTRAL":
            mood_status = "neutral"
        elif sentiment == "NEGATIVE":
            mood_status = "sad"
        else:
            mood_status = "neutral"

        update_mood_entry(entry['id'], mood_status)

        # Check for potential suicidal intent (sad mood with high confidence)
        if mood_status == "sad" and confidence > 0.8:
            print(f"Triggering email notification for User ID: {entry['user_id']}")
            send_admin_notification(entry['user_id'], mood_note)

if __name__ == "__main__":
    while True:
        print(f"\n[{datetime.now()}] Checking for new mood entries...")
        analyze_mood_entries()
        print(f"[{datetime.now()}] Sleeping for 3 minutes...\n")
        time.sleep(18)  # Sleep for 180 seconds (3 minutes)

